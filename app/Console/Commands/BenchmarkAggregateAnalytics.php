<?php

namespace App\Console\Commands;

use App\Models\DailyStat;
use App\Models\Session;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BenchmarkAggregateAnalytics extends Command
{
    protected $signature = 'benchmark:aggregate-analytics
                            {--date= : Y-m-d, defaults to yesterday}
                            {--sites=1 : Number of sites to aggregate}
                            {--duration : Show brief duration instead of detailed stats}
                            {--iterations=5 : Run the benchmark multiple times}';

    protected $description = 'Benchmark the aggregation command with detailed operation timing';

    private array $operationTimings = [];
    private int $sessionsProcessed = 0;
    private int $aggregationTime = 0;

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : today()->subDay()->toDateString();

        $numSites = (int) $this->option('sites');
        $iterations = (int) $this->option('iterations');
        $briefOutput = $this->option('duration');

        // Initialize operation timings
        $operations = [
            'db_delete', 'query_visitors', 'query_pageviews', 'query_bounced',
            'query_avg_duration', 'calc_bounce_rate', 'calc_vpp',
            'agg_channel', 'agg_referrer', 'agg_utm_source', 'agg_utm_medium',
            'agg_utm_campaign', 'agg_utm_content', 'agg_utm_term',
            'agg_country', 'agg_browsers', 'agg_os', 'agg_devices',
            'agg_regions', 'agg_cities', 'agg_top_pages', 'agg_entry_pages',
            'agg_exit_pages', 'db_upsert', 'total'
        ];
        foreach ($operations as $op) {
            $this->operationTimings[$op] = [];
        }

        // Get sites to aggregate
        $sites = Site::limit($numSites)->get();
        if ($sites->isEmpty()) {
            $this->error("No sites found.");
            return self::FAILURE;
        }

        $this->info("🚀 Benchmarking Aggregation Analytics");
        $this->info("────────────────────────────────────");
        $this->line("Date: <fg=cyan>{$date}</>");
        $this->line("Sites: <fg=cyan>" . $sites->count() . "</>");
        $this->line("Iterations: <fg=cyan>{$iterations}</>");
        $this->newLine();

        // Run iterations
        for ($iter = 0; $iter < $iterations; $iter++) {
            if ($iterations > 1) {
                $this->line("Iteration <fg=yellow>" . ($iter + 1) . "</> of <fg=yellow>{$iterations}</>");
            }

            $sites->each(function (Site $site) use ($date): void {
                $this->benchmarkAggregateSite($site, $date);
            });

            if ($iterations > 1 && $iter < $iterations - 1) {
                $this->newLine();
            }
        }

        $this->newLine();
        if ($briefOutput) {
            $this->displayBriefResults();
        } else {
            $this->displayDetailedResults($iterations);
        }

        return self::SUCCESS;
    }

    private function benchmarkAggregateSite(Site $site, string $date): void
    {
        $startTotal = microtime(true);

        // Count sessions for this site/date
        $sessionCount = Session::where('site_id', $site->id)
            ->whereDate('started_at', $date)
            ->count();

        if ($sessionCount === 0) {
            $this->warn("No sessions found for site {$site->domain} on {$date}");
            return;
        }

        $this->sessionsProcessed += $sessionCount;

        // Base query
        $sessQ = fn () => Session::where('site_id', $site->id)
            ->whereDate('started_at', $date);

        // Visitors
        $start = microtime(true);
        $visitors = $sessQ()->count();
        $this->recordTiming('query_visitors', $start);

        // Pageviews
        $start = microtime(true);
        $pageviews = $sessQ()->sum('pageviews');
        $this->recordTiming('query_pageviews', $start);

        // Bounced
        $start = microtime(true);
        $bounced = $sessQ()->where('is_bounce', true)->count();
        $this->recordTiming('query_bounced', $start);

        // Avg duration
        $start = microtime(true);
        $avgDuration = (int) $sessQ()->whereNotNull('duration')->avg('duration');
        $this->recordTiming('query_avg_duration', $start);

        // Calculations
        $start = microtime(true);
        $bounceRate = $visitors > 0 ? round($bounced / $visitors * 100, 2) : null;
        $this->recordTiming('calc_bounce_rate', $start);

        $start = microtime(true);
        $vpp = $visitors > 0 ? round($pageviews / $visitors, 2) : null;
        $this->recordTiming('calc_vpp', $start);

        // Generic aggregation function
        $agg = function (string $groupCol) use ($sessQ): array {
            return $sessQ()
                ->whereNotNull($groupCol)
                ->groupBy($groupCol)
                ->select(
                    DB::raw("{$groupCol} as grp_key"),
                    DB::raw('COUNT(*) as visitors'),
                    DB::raw('SUM(pageviews) as pageviews'),
                    DB::raw('ROUND(AVG(CASE WHEN is_bounce THEN 100.0 ELSE 0 END), 1) as bounce_rate'),
                    DB::raw('ROUND(AVG(COALESCE(duration, 0)), 0) as avg_duration'),
                )
                ->orderByDesc('visitors')
                ->get()
                ->map(fn ($r) => [
                    'key' => $r->grp_key,
                    'visitors' => (int) $r->visitors,
                    'pageviews' => (int) $r->pageviews,
                    'bounce_rate' => (float) $r->bounce_rate,
                    'avg_duration' => (int) $r->avg_duration,
                ])
                ->values()
                ->toArray();
        };

        // Aggregations by column
        $start = microtime(true);
        $channelsAgg = $agg('channel');
        $this->recordTiming('agg_channel', $start);

        $start = microtime(true);
        $referrersAgg = $agg('referrer_domain');
        $this->recordTiming('agg_referrer', $start);

        $start = microtime(true);
        $utmSourcesAgg = $agg('utm_source');
        $this->recordTiming('agg_utm_source', $start);

        $start = microtime(true);
        $utmMediumsAgg = $agg('utm_medium');
        $this->recordTiming('agg_utm_medium', $start);

        $start = microtime(true);
        $utmCampaignsAgg = $agg('utm_campaign');
        $this->recordTiming('agg_utm_campaign', $start);

        $start = microtime(true);
        $utmContentsAgg = $agg('utm_content');
        $this->recordTiming('agg_utm_content', $start);

        $start = microtime(true);
        $utmTermsAgg = $agg('utm_term');
        $this->recordTiming('agg_utm_term', $start);

        // Geographic aggregations
        $start = microtime(true);
        $countriesAgg = $agg('country_code');
        $this->recordTiming('agg_country', $start);

        $start = microtime(true);
        $browsersAgg = $sessQ()
            ->whereNotNull('browser')
            ->groupBy('browser', 'browser_version')
            ->select('browser', 'browser_version', DB::raw('COUNT(*) as visitors'))
            ->orderByDesc('visitors')
            ->get()
            ->groupBy('browser')
            ->map(fn ($rows, $browser) => [
                'key' => $browser,
                'visitors' => (int) $rows->sum('visitors'),
                'versions' => $rows
                    ->sortByDesc('visitors')
                    ->map(fn ($r) => ['key' => $r->browser_version, 'visitors' => (int) $r->visitors])
                    ->values()
                    ->toArray(),
            ])
            ->sortByDesc('visitors')
            ->values()
            ->toArray();
        $this->recordTiming('agg_browsers', $start);

        $start = microtime(true);
        $osAgg = $sessQ()
            ->whereNotNull('os')
            ->groupBy('os', 'os_version')
            ->select('os', 'os_version', DB::raw('COUNT(*) as visitors'))
            ->orderByDesc('visitors')
            ->get()
            ->groupBy('os')
            ->map(fn ($rows, $os) => [
                'key' => $os,
                'visitors' => (int) $rows->sum('visitors'),
                'versions' => $rows
                    ->sortByDesc('visitors')
                    ->map(fn ($r) => ['key' => $r->os_version, 'visitors' => (int) $r->visitors])
                    ->values()
                    ->toArray(),
            ])
            ->sortByDesc('visitors')
            ->values()
            ->toArray();
        $this->recordTiming('agg_os', $start);

        $start = microtime(true);
        $devicesAgg = $agg('device_type');
        $this->recordTiming('agg_devices', $start);

        $start = microtime(true);
        $regionsAgg = $sessQ()
            ->whereNotNull('subdivision_code')
            ->groupBy('subdivision_code', 'country_code')
            ->select('subdivision_code', 'country_code', DB::raw('COUNT(*) as visitors'))
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($r) => [
                'key' => $r->subdivision_code,
                'country_code' => $r->country_code,
                'visitors' => (int) $r->visitors,
            ])
            ->toArray();
        $this->recordTiming('agg_regions', $start);

        $start = microtime(true);
        $citiesAgg = $sessQ()
            ->whereNotNull('city')
            ->groupBy('city', 'subdivision_code', 'country_code')
            ->select('city', 'subdivision_code', 'country_code', DB::raw('COUNT(*) as visitors'))
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($r) => [
                'key' => $r->city,
                'subdivision_code' => $r->subdivision_code,
                'country_code' => $r->country_code,
                'visitors' => (int) $r->visitors,
            ])
            ->toArray();
        $this->recordTiming('agg_cities', $start);

        // Page aggregations
        $start = microtime(true);
        $topPagesAgg = $sessQ()
            ->selectRaw('entry_page as pathname, SUM(pageviews) as pageviews, COUNT(DISTINCT visitor_id) as visitors')
            ->groupBy('entry_page')
            ->orderByDesc('pageviews')
            ->get()
            ->map(fn ($r) => ['key' => $r->pathname, 'pageviews' => (int) $r->pageviews, 'visitors' => (int) $r->visitors])
            ->toArray();
        $this->recordTiming('agg_top_pages', $start);

        $start = microtime(true);
        $entryPagesAgg = $sessQ()
            ->groupBy('entry_page')
            ->select('entry_page', DB::raw('COUNT(*) as visitors'), DB::raw('ROUND(AVG(CASE WHEN is_bounce THEN 100.0 ELSE 0 END),1) as bounce_rate'))
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($r) => ['key' => $r->entry_page, 'visitors' => (int) $r->visitors, 'bounce_rate' => (float) $r->bounce_rate])
            ->toArray();
        $this->recordTiming('agg_entry_pages', $start);

        $start = microtime(true);
        $exitPagesAgg = $sessQ()
            ->groupBy('exit_page')
            ->select('exit_page', DB::raw('COUNT(*) as visitors'))
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($r) => ['key' => $r->exit_page, 'visitors' => (int) $r->visitors])
            ->toArray();
        $this->recordTiming('agg_exit_pages', $start);

        // Database delete
        $start = microtime(true);
        DailyStat::where('site_id', $site->id)
            ->whereDate('date', $date)
            ->delete();
        $this->recordTiming('db_delete', $start);

        // Database insert
        $start = microtime(true);
        DailyStat::create([
            'site_id' => $site->id,
            'date' => $date,
            'visitors' => $visitors,
            'pageviews' => $pageviews,
            'views_per_visit' => $vpp,
            'bounce_rate' => $bounceRate,
            'avg_duration' => $avgDuration,
            'channels_agg' => $channelsAgg,
            'referrers_agg' => $referrersAgg,
            'utm_sources_agg' => $utmSourcesAgg,
            'utm_mediums_agg' => $utmMediumsAgg,
            'utm_campaigns_agg' => $utmCampaignsAgg,
            'utm_contents_agg' => $utmContentsAgg,
            'utm_terms_agg' => $utmTermsAgg,
            'countries_agg' => $countriesAgg,
            'regions_agg' => $regionsAgg,
            'cities_agg' => $citiesAgg,
            'browsers_agg' => $browsersAgg,
            'os_agg' => $osAgg,
            'devices_agg' => $devicesAgg,
            'top_pages_agg' => $topPagesAgg,
            'entry_pages_agg' => $entryPagesAgg,
            'exit_pages_agg' => $exitPagesAgg,
        ]);
        $this->recordTiming('db_upsert', $start);

        $totalTime = (microtime(true) - $startTotal) * 1000;
        $this->operationTimings['total'][] = $totalTime;

        $this->line(sprintf("✓ Site: <fg=cyan>%s</> | Sessions: <fg=cyan>%d</> | Time: <fg=yellow>%.2f ms</>", $site->domain, $sessionCount, $totalTime));
    }

    private function recordTiming(string $operation, float $startTime): void
    {
        $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        $this->operationTimings[$operation][] = $duration;
    }

    private function displayBriefResults(): void
    {
        $totalTime = array_sum($this->operationTimings['total']);
        $avgTime = !empty($this->operationTimings['total'])
            ? $totalTime / count($this->operationTimings['total'])
            : 0;
        $minTime = !empty($this->operationTimings['total']) ? min($this->operationTimings['total']) : 0;
        $maxTime = !empty($this->operationTimings['total']) ? max($this->operationTimings['total']) : 0;

        $this->info(sprintf('⏱️  Total Time: %.2f seconds', $totalTime / 1000));
        $this->info(sprintf('⏱️  Average per aggregation: %.2f ms (min: %.2f ms, max: %.2f ms)', $avgTime, $minTime, $maxTime));
        $this->info(sprintf('📊 Sessions processed: %d', $this->sessionsProcessed));
    }

    private function displayDetailedResults(int $iterations): void
    {
        $this->info('📊 Aggregation Benchmark Results');
        $this->info('════════════════════════════════════════════════════════');

        // Overall stats
        $this->newLine();
        $this->line('<fg=cyan>⏱️  Overall Statistics</>');
        $totalTime = array_sum($this->operationTimings['total']);
        $avgTime = !empty($this->operationTimings['total'])
            ? $totalTime / count($this->operationTimings['total'])
            : 0;
        $minTime = !empty($this->operationTimings['total']) ? min($this->operationTimings['total']) : 0;
        $maxTime = !empty($this->operationTimings['total']) ? max($this->operationTimings['total']) : 0;

        $this->line(sprintf('  Total Time:         <fg=cyan>%.2f seconds</>', $totalTime / 1000));
        $this->line(sprintf('  Aggregations:       <fg=cyan>%d</>', count($this->operationTimings['total'])));
        $this->line(sprintf('  Sessions Processed: <fg=cyan>%d</>', $this->sessionsProcessed));
        $this->line(sprintf('  Per Aggregation:    <fg=yellow>%.2f ms</>', $avgTime));
        $this->line(sprintf('  Min Time:           <fg=green>%.2f ms</>', $minTime));
        $this->line(sprintf('  Max Time:           <fg=red>%.2f ms</>', $maxTime));

        if ($this->sessionsProcessed > 0 && $totalTime > 0) {
            $sessionsPerSecond = ($this->sessionsProcessed / $totalTime) * 1000;
            $this->line(sprintf('  Throughput:         <fg=cyan>%.0f sessions/sec</>', $sessionsPerSecond));
        }

        // Operation breakdown
        $this->newLine();
        $this->displayOperationBreakdown($avgTime);

        $this->newLine();
        $this->info('════════════════════════════════════════════════════════');
    }

    private function displayOperationBreakdown(float $avgTotalTime): void
    {
        $this->line('<fg=cyan>🔍 Operation Timing Breakdown (average per aggregation)</>');

        // Calculate averages
        $operationAverages = [];
        foreach ($this->operationTimings as $operation => $timings) {
            if (!empty($timings)) {
                $operationAverages[$operation] = array_sum($timings) / count($timings);
            }
        }

        // Sort by time (descending)
        arsort($operationAverages);

        // Exclude total and overall timing from percentage calculation
        $excludeOps = ['total'];
        $relevantOps = array_filter($operationAverages, fn($k) => !in_array($k, $excludeOps), ARRAY_FILTER_USE_KEY);
        $totalOpsTime = array_sum($relevantOps);

        $this->newLine();
        $categories = [
            'Query Operations' => ['query_visitors', 'query_pageviews', 'query_bounced', 'query_avg_duration'],
            'Calculations' => ['calc_bounce_rate', 'calc_vpp'],
            'Aggregations (UTM & Channels)' => ['agg_channel', 'agg_referrer', 'agg_utm_source', 'agg_utm_medium', 'agg_utm_campaign', 'agg_utm_content', 'agg_utm_term'],
            'Aggregations (Geography)' => ['agg_country', 'agg_regions', 'agg_cities'],
            'Aggregations (Browser/Device)' => ['agg_browsers', 'agg_os', 'agg_devices'],
            'Aggregations (Pages)' => ['agg_top_pages', 'agg_entry_pages', 'agg_exit_pages'],
            'Database Operations' => ['db_delete', 'db_upsert'],
        ];

        foreach ($categories as $category => $operations) {
            $categoryTime = 0;
            $categoryOps = [];

            foreach ($operations as $op) {
                if (isset($operationAverages[$op]) && !in_array($op, $excludeOps)) {
                    $categoryTime += $operationAverages[$op];
                    $categoryOps[$op] = $operationAverages[$op];
                }
            }

            if (empty($categoryOps)) {
                continue;
            }

            $percentage = $totalOpsTime > 0 ? ($categoryTime / $totalOpsTime) * 100 : 0;
            $this->line(sprintf('  <fg=cyan>%s</> <fg=yellow>(%.1f%%)</> - <fg=green>%.2f ms</>', $category, $percentage, $categoryTime));

            // Individual operations in category
            arsort($categoryOps);
            foreach ($categoryOps as $op => $time) {
                $opPercentage = $totalOpsTime > 0 ? ($time / $totalOpsTime) * 100 : 0;
                $barLength = max(1, (int) ($opPercentage / 2));
                $bar = str_repeat('▌', $barLength);

                $color = $opPercentage > 15 ? 'yellow' : 'green';
                $this->line(sprintf('    <fg=%s>%s</> %-30s <fg=%s>%6.1f%%</> <fg=cyan>%7.2f ms</>', 
                    $color, 
                    $bar, 
                    $this->formatOperationName($op), 
                    $color, 
                    $opPercentage, 
                    $time
                ));
            }

            $this->newLine();
        }

        // Top 5 slowest operations (excluding total)
        $this->line('<fg=yellow>⚠️  Top 5 Slowest Operations:</> (optimization opportunities)');
        $sorted = array_filter($operationAverages, fn($k) => $k !== 'total', ARRAY_FILTER_USE_KEY);
        arsort($sorted);
        $top5 = array_slice($sorted, 0, 5, true);

        $rank = 1;
        foreach ($top5 as $operation => $time) {
            $percentage = $totalOpsTime > 0 ? ($time / $totalOpsTime) * 100 : 0;
            $this->line(sprintf('  <fg=yellow>%d.</> %s: <fg=red>%.2f ms (%.1f%%)</>', 
                $rank++, 
                $this->formatOperationName($operation), 
                $time, 
                $percentage
            ));
        }
    }

    private function formatOperationName(string $operation): string
    {
        $names = [
            'query_visitors' => 'Query Visitors',
            'query_visitors' => 'Query Visitors',
            'query_pageviews' => 'Query Pageviews',
            'query_bounced' => 'Query Bounced',
            'query_avg_duration' => 'Query Avg Duration',
            'calc_bounce_rate' => 'Calculate Bounce Rate',
            'calc_vpp' => 'Calculate Views/Visit',
            'agg_channel' => 'Aggregate Channels',
            'agg_referrer' => 'Aggregate Referrers',
            'agg_utm_source' => 'Aggregate UTM Source',
            'agg_utm_medium' => 'Aggregate UTM Medium',
            'agg_utm_campaign' => 'Aggregate UTM Campaign',
            'agg_utm_content' => 'Aggregate UTM Content',
            'agg_utm_term' => 'Aggregate UTM Term',
            'agg_country' => 'Aggregate Countries',
            'agg_regions' => 'Aggregate Regions',
            'agg_cities' => 'Aggregate Cities',
            'agg_browsers' => 'Aggregate Browsers',
            'agg_os' => 'Aggregate Operating Systems',
            'agg_devices' => 'Aggregate Devices',
            'agg_top_pages' => 'Aggregate Top Pages',
            'agg_entry_pages' => 'Aggregate Entry Pages',
            'agg_exit_pages' => 'Aggregate Exit Pages',
            'db_delete' => 'Database Delete',
            'db_upsert' => 'Database Insert',
        ];

        return $names[$operation] ?? $operation;
    }
}
