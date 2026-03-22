<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\HTTP;

class BenchmarkPageviewApi extends Command
{
    protected $signature = 'benchmark:pageview-api
                            {--requests=100 : Total number of requests to send}
                            {--concurrency=10 : Number of concurrent requests}
                            {--domain=example.com : Target site domain}
                            {--duration : Show brief duration instead of detailed stats}';

    protected $description = 'Benchmark the pageview API endpoint with concurrent load testing';

    private array $responseTimes = [];
    private int $successCount = 0;
    private int $failureCount = 0;
    private array $statusCodes = [];
    private float $totalBytes = 0;
    private array $errors = [];
    private array $operationTimings = []; // Track timings per operation
    private string $siteId = ''; // Store site ID once

    public function handle(): int
    {
        $totalRequests = (int) $this->option('requests');
        $concurrency = (int) $this->option('concurrency');
        $domain = $this->option('domain');
        $briefOutput = $this->option('duration');

        // Initialize operation timings
        $operations = [
            'validation', 'site_lookup', 'visitor_hash', 'parse_browser',
            'parse_device', 'parse_referrer', 'classify_channel', 'geoip_lookup',
            'session_lookup', 'session_upsert', 'update_exit_flags', 'create_pageview', 'total'
        ];
        foreach ($operations as $op) {
            $this->operationTimings[$op] = [];
        }

        // Verify site exists
        $site = Site::where('domain', $domain)->first();
        if (!$site) {
            $this->error("Site with domain '{$domain}' not found.");
            return self::FAILURE;
        }

        $this->siteId = $site->public_id;

        $this->info("🚀 Benchmarking Pageview API");
        $this->info("────────────────────────────");
        $this->line("Domain: <fg=cyan>{$domain}</>");
        $this->line("Total Requests: <fg=cyan>{$totalRequests}</>");
        $this->line("Concurrency: <fg=cyan>{$concurrency}</>");
        $this->newLine();

        $startTime = microtime(true);

        // Send requests in batches
        $batchSize = min($concurrency, $totalRequests);
        for ($i = 0; $i < $totalRequests; $i += $batchSize) {
            $batch = min($batchSize, $totalRequests - $i);
            $this->sendBatch($batch);
            $this->display_progress($i + $batch, $totalRequests);
        }

        $duration = microtime(true) - $startTime;

        $this->newLine();
        if ($briefOutput) {
            $this->info(sprintf('⏱️  Total Duration: %.2f seconds', $duration));
        } else {
            $this->displayResults($duration, $totalRequests);
        }

        return self::SUCCESS;
    }

    private function sendBatch(int $count): void
    {
        $requests = [];

        for ($i = 0; $i < $count; $i++) {
            $payload = $this->generatePayload();
            $requests[] = $this->createBenchmarkRequest($payload);
        }

        // Execute all requests and record timing
        foreach ($requests as $request) {
            $startTime = microtime(true);

            try {
                $response = HTTP::withHeaders(['X-Benchmark' => 'true'])
                    ->post(url('/api/pageview'), $request);
                $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

                $this->recordResponse($response, $duration);
                $this->recordOperationTimings($response);
            } catch (\Exception $e) {
                $this->recordError($e);
            }
        }
    }

    private function recordOperationTimings($response): void
    {
        $timingHeader = $response->header('X-Timing-Breakdown');
        if ($timingHeader) {
            try {
                $timings = json_decode($timingHeader, true);
                if (is_array($timings)) {
                    foreach ($timings as $operation => $milliseconds) {
                        $this->operationTimings[$operation][] = $milliseconds;
                    }
                }
            } catch (\Exception $e) {
                // Silent fail if timing data format is invalid
            }
        }
    }

    private function generatePayload(): array
    {
        $pages = [
            '/',
            '/blog',
            '/blog/how-to-get-started',
            '/pricing',
            '/features',
            '/about',
            '/contact',
            '/docs',
        ];

        $utmSources = ['direct', 'google', 'facebook', 'twitter', 'linkedin', null];
        $utmMediums = ['organic', 'cpc', 'social', 'email', null];
        $screenWidths = [375, 768, 1024, 1440, 1920];

        return [
            'site_id' => $this->siteId,
            'pathname' => $pages[array_rand($pages)],
            'screen_width' => $screenWidths[array_rand($screenWidths)],
            'utm_source' => $utmSources[array_rand($utmSources)],
            'utm_medium' => $utmMediums[array_rand($utmMediums)],
            'utm_campaign' => rand(0, 1) ? 'campaign_' . rand(1, 5) : null,
            'referrer' => rand(0, 1) ? 'https://google.com/search?q=test' : null,
        ];
    }

    private function createBenchmarkRequest(array $payload): array
    {
        return $payload;
    }

    private function recordResponse($response, float $duration): void
    {
        $this->responseTimes[] = $duration;
        $statusCode = $response->status();

        if ($response->successful()) {
            $this->successCount++;
        } else {
            $this->failureCount++;
            $this->errors[] = "Status {$statusCode}";
        }

        $this->statusCodes[$statusCode] = ($this->statusCodes[$statusCode] ?? 0) + 1;
        $this->totalBytes += strlen($response->body());
    }

    private function recordError(\Exception $e): void
    {
        $this->failureCount++;
        $this->errors[] = $e->getMessage();
    }

    private function display_progress(int $current, int $total): void
    {
        $percent = ($current / $total) * 100;
        $bar = str_repeat('=', (int) ($percent / 2));
        $this->line(sprintf(
            "\r<fg=green>%s</>%s %d/%d (%.0f%%)",
            $bar,
            str_repeat(' ', 50 - strlen($bar)),
            $current,
            $total,
            $percent
        ));
    }

    private function displayResults(float $duration, int $totalRequests): void
    {
        $this->info('📊 Benchmark Results');
        $this->info('════════════════════════════════════════');

        // Timing stats
        if (!empty($this->responseTimes)) {
            $minTime = min($this->responseTimes);
            $maxTime = max($this->responseTimes);
            $avgTime = array_sum($this->responseTimes) / count($this->responseTimes);
            $medianTime = $this->getMedian($this->responseTimes);

            $this->newLine();
            $this->line('<fg=cyan>⏱️  Response Times (ms)</>');
            $this->line(sprintf('  Min:       <fg=green>%.2f ms</>', $minTime));
            $this->line(sprintf('  Max:       <fg=red>%.2f ms</>', $maxTime));
            $this->line(sprintf('  Average:   <fg=yellow>%.2f ms</>', $avgTime));
            $this->line(sprintf('  Median:    <fg=cyan>%.2f ms</>', $medianTime));
        }

        // Operation timing breakdown
        if (!empty($this->operationTimings['total']) && count($this->operationTimings['total']) > 0) {
            $this->displayOperationTimingBreakdown();
        }

        // Request stats
        $this->newLine();
        $this->line('<fg=cyan>📈 Request Statistics</>');
        $this->line(sprintf('  Total Requests:  %d', $totalRequests));
        $this->line(sprintf('  Successful:      <fg=green>%d (%.1f%%)</>', $this->successCount, ($this->successCount / $totalRequests) * 100));
        $this->line(sprintf('  Failed:          <fg=red>%d (%.1f%%)</>', $this->failureCount, ($this->failureCount / $totalRequests) * 100));

        // Throughput
        $this->newLine();
        $this->line('<fg=cyan>🚀 Throughput</>');
        $rps = $duration > 0 ? $totalRequests / $duration : 0;
        $this->line(sprintf('  Requests/sec:   <fg=yellow>%.2f</>', $rps));
        $this->line(sprintf('  Duration:        <fg=cyan>%.2f seconds</>', $duration));

        // Status codes breakdown
        if (!empty($this->statusCodes)) {
            $this->newLine();
            $this->line('<fg=cyan>📋 Status Codes</>');
            foreach ($this->statusCodes as $code => $count) {
                $percent = ($count / $totalRequests) * 100;
                $color = $code >= 200 && $code < 300 ? 'green' : ($code >= 400 ? 'red' : 'yellow');
                $this->line(sprintf('  %d: <fg=%s>%d (%.1f%%)</>', $code, $color, $count, $percent));
            }
        }

        // Data transferred
        if ($this->totalBytes > 0) {
            $this->newLine();
            $this->line('<fg=cyan>📦 Data Transfer</>');
            $this->line(sprintf('  Total Bytes:     %.2f KB', $this->totalBytes / 1024));
            $this->line(sprintf('  Avg per Request: %.2f KB', ($this->totalBytes / $totalRequests) / 1024));
        }

        // Errors
        if (!empty($this->errors)) {
            $this->newLine();
            $this->line('<fg=red>❌ Errors (' . count($this->errors) . ')</>');
            $errorCounts = array_count_values($this->errors);
            foreach ($errorCounts as $error => $count) {
                $this->line(sprintf('  %s: %d', $error, $count));
            }
        }

        $this->newLine();
        $this->info('════════════════════════════════════════');
    }

    private function displayOperationTimingBreakdown(): void
    {
        $this->newLine();
        $this->line('<fg=cyan>🔍 Operation Timing Breakdown (per request)</>');

        // Calculate averages for each operation
        $operationAverages = [];
        $operationCounts = [];

        foreach ($this->operationTimings as $operation => $timings) {
            if (!empty($timings)) {
                $operationAverages[$operation] = array_sum($timings) / count($timings);
                $operationCounts[$operation] = count($timings);
            }
        }

        // Skip 'total' for percentage calculation
        $totalTime = $operationAverages['total'] ?? 0;
        $excludeOps = ['total'];

        if ($totalTime > 0) {
            // Sort by average time (descending)
            arsort($operationAverages);

            // Display detailed breakdown
            foreach ($operationAverages as $operation => $avgTime) {
                if (in_array($operation, $excludeOps)) {
                    continue;
                }

                $percentage = ($avgTime / $totalTime) * 100;
                $barLength = max(1, (int) ($percentage / 2));
                $bar = str_repeat('█', $barLength);

                // Color code: red for > 40%, yellow for 20-40%, green for < 20%
                if ($percentage > 40) {
                    $color = 'red';
                } elseif ($percentage > 20) {
                    $color = 'yellow';
                } else {
                    $color = 'green';
                }

                $this->line(sprintf(
                    '  <fg=%s>%s</> %-35s <fg=%s>%6.2f%%</> <fg=cyan>%7.2f ms</>',
                    $color,
                    $bar,
                    $this->formatOperationName($operation),
                    $color,
                    $percentage,
                    $avgTime
                ));
            }

            // Summary line
            $this->newLine();
            $this->line(sprintf('  <fg=cyan>Total per request: %.2f ms</>', $totalTime));

            // Identify bottlenecks
            $sorted = array_filter($operationAverages, fn($op) => !in_array(array_search($op, $operationAverages), $excludeOps));
            arsort($sorted);
            $topOps = array_slice($sorted, 0, 3, true);

            if (!empty($topOps)) {
                $this->newLine();
                $this->line('<fg=yellow>⚠️  Top 3 Bottlenecks:</> (optimization opportunities)');
                $rank = 1;
                foreach ($topOps as $operation => $avgTime) {
                    $percentage = ($avgTime / $totalTime) * 100;
                    $this->line(sprintf('  <fg=yellow>%d.</> %s: <fg=red>%.2f ms (%.1f%%)</>', 
                        $rank++, 
                        $this->formatOperationName($operation), 
                        $avgTime, 
                        $percentage
                    ));
                }
            }
        }
    }

    private function formatOperationName(string $operation): string
    {
        $names = [
            'validation' => 'Request Validation',
            'site_lookup' => 'Site Lookup (DB)',
            'visitor_hash' => 'Visitor Hash Generation',
            'parse_browser' => 'Parse Browser Info',
            'parse_device' => 'Parse Device Info',
            'parse_referrer' => 'Parse Referrer Domain',
            'classify_channel' => 'Channel Classification',
            'geoip_lookup' => 'GeoIP Lookup',
            'session_lookup' => 'Session Lookup (DB)',
            'session_upsert' => 'Session Create/Update (DB)',
            'update_exit_flags' => 'Update Exit Flags (DB)',
            'create_pageview' => 'Create Pageview (DB)',
        ];

        return $names[$operation] ?? $operation;
    }

    private function getMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = (int) ($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }
}
