<?php

namespace Database\Seeders;

use App\Enums\DeviceType;
use App\Enums\SiteRole;
use App\Models\Pageview;
use App\Models\Session;
use App\Models\Site;
use App\Models\SiteUser;
use App\Services\ChannelClassifier;
use App\Services\VisitorHash;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class AnalyticsSeeder extends Seeder
{
    private ChannelClassifier $channelClassifier;
    private const REPEAT_VISITOR_PERCENTAGE = 20;

    public function run(): void
    {

        $days = 30;
        $this->channelClassifier = app(ChannelClassifier::class);

        // Create or get site
        $site = Site::updateOrCreate(
            ['domain' => 'example.com'],
            [
                'name' => 'Example Website',
                'timezone' => 'UTC',
                'is_public' => false,
            ]
        );

        SiteUser::updateOrCreate(
            ['site_id' => $site->id, 'user_id' => 1],
            ['role' => SiteRole::Admin->value]
        );

        // Generate sessions for last 7 days (configurable)
        $this->generateAnalyticsData($site, $days);

        // Aggregate all generated data
        $this->aggregateData($site, $days);

        $this->command->info('Analytics data seeded and aggregated.');
    }

    private function generateAnalyticsData(Site $site, int $days): void
    {
        $topPages = [
            '/',
            '/blog',
            '/blog/how-to-get-started',
            '/blog/advanced-guide',
            '/pricing',
            '/features',
            '/about',
            '/contact',
            '/docs',
            '/docs/api',
            '/team',
        ];

        $countries = [
            ['country_code' => 'US', 'subdivision' => 'CA', 'city' => 'San Francisco', 'weight' => 0.3],
            ['country_code' => 'US', 'subdivision' => 'NY', 'city' => 'New York', 'weight' => 0.2],
            ['country_code' => 'GB', 'subdivision' => 'ENG', 'city' => 'London', 'weight' => 0.15],
            ['country_code' => 'DE', 'subdivision' => 'BE', 'city' => 'Berlin', 'weight' => 0.1],
            ['country_code' => 'FR', 'subdivision' => '92', 'city' => 'Paris', 'weight' => 0.1],
            ['country_code' => 'CA', 'subdivision' => 'ON', 'city' => 'Toronto', 'weight' => 0.08],
            ['country_code' => 'AU', 'subdivision' => 'NSW', 'city' => 'Sydney', 'weight' => 0.07],
        ];

        $browsers = [
            ['name' => 'Chrome', 'weight' => 0.60, 'versions' => ['120', '121', '122']],
            ['name' => 'Safari', 'weight' => 0.20, 'versions' => ['17', '18']],
            ['name' => 'Firefox', 'weight' => 0.15, 'versions' => ['121', '122']],
            ['name' => 'Edge', 'weight' => 0.05, 'versions' => ['120', '121']],
        ];

        $operatingSystems = [
            ['name' => 'Android', 'weight' => 0.42, 'versions' => ['13', '14', '15']],
            ['name' => 'iOS', 'weight' => 0.28, 'versions' => ['17', '18']],
            ['name' => 'Windows', 'weight' => 0.18, 'versions' => ['10', '11']],
            ['name' => 'macOS', 'weight' => 0.12, 'versions' => ['13', '14']],
        ];

        $devices = [
            ['type' => DeviceType::Mobile, 'weight' => 0.70],
            ['type' => DeviceType::Desktop, 'weight' => 0.25],
            ['type' => DeviceType::Tablet, 'weight' => 0.05],
        ];

        $trafficSources = [
            // Direct
            ['source' => null, 'medium' => null, 'campaign' => null, 'weight' => 0.80],
            // Organic search
            ['source' => 'google', 'medium' => 'organic', 'campaign' => null, 'weight' => 0.08],
            ['source' => 'bing', 'medium' => 'organic', 'campaign' => null, 'weight' => 0.02],
            // Organic social
            ['source' => 'twitter', 'medium' => 'social', 'campaign' => null, 'weight' => 0.04],
            ['source' => 'linkedin', 'medium' => 'social', 'campaign' => null, 'weight' => 0.03],
            // Paid search
            ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'summer_campaign', 'weight' => 0.02],
            ['source' => 'bing', 'medium' => 'cpc', 'campaign' => 'summer_campaign', 'weight' => 0.01],
        ];

        $startDate = today()->subDays($days - 1);
        
        // Create a pool of regular visitors (50% of sessions will be from repeat visitors)
        $regularVisitors = [];
        $regularVisitorCount = 10;
        for ($i = 0; $i < $regularVisitorCount; $i++) {
            $ip = implode('.', [rand(100, 200), rand(1, 255), rand(1, 255), '0']);
            $browser = $this->weightedRandom($browsers);
            $os = $this->weightedRandom($operatingSystems);
            $userAgent = $this->getUserAgent($browser['name'], $os['name']);
            $visitorId = VisitorHash::make($ip, $userAgent, $site->domain);
            $regularVisitors[] = [
                'visitor_id' => $visitorId,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'browser' => $browser['name'],
                'os' => $os['name'],
            ];
        }

        $sessionBatch = [];
        $pageviewBatch = [];
        $sessionIdMap = []; // Track session IDs for pageview mapping

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i);
            $sessionCount = rand(20, 100);

            for ($s = 0; $s < $sessionCount; $s++) {
                $traffic = $this->weightedRandom($trafficSources);
                $device = $this->weightedRandom($devices);
                $location = $this->weightedRandom($countries);

                if (rand(1, 100) <= self::REPEAT_VISITOR_PERCENTAGE) {
                    $regularVisitor = $regularVisitors[array_rand($regularVisitors)];
                    $visitorId = $regularVisitor['visitor_id'];
                    $browserName = $regularVisitor['browser'];
                    $osName = $regularVisitor['os'];
                } else {
                    // Create new visitor
                    $ip = implode('.', [rand(1, 255), rand(1, 255), rand(1, 255), '0']);
                    $browser = $this->weightedRandom($browsers);
                    $os = $this->weightedRandom($operatingSystems);
                    $browserName = $browser['name'];
                    $osName = $os['name'];
                    $userAgent = $this->getUserAgent($browserName, $osName);
                    $visitorId = VisitorHash::make($ip, $userAgent, $site->domain);
                }

                // Get browser/OS versions
                $browserData = collect($browsers)->firstWhere('name', $browserName);
                $browserVersion = $browserData['versions'][array_rand($browserData['versions'])];
                
                $osData = collect($operatingSystems)->firstWhere('name', $osName);
                $osVersion = $osData['versions'][array_rand($osData['versions'])];

                $channel = $this->channelClassifier->classify(
                    $traffic['source'],
                    $traffic['medium'],
                    $traffic['campaign'],
                    $traffic['source'],
                );

                $sessionTime = $date->copy()
                    ->setHour(rand(0, 23))
                    ->setMinute(rand(0, 59))
                    ->setSecond(rand(0, 59));

                $isBounce = rand(1, 100) <= 30;
                $pageviewCount = $isBounce ? 1 : rand(2, 5);
                $duration = $isBounce ? 0 : rand(10, 600);

                // Select entry page with weighted distribution
                $entryPage = $this->weightedPageSelection($topPages);

                $screenWidth = match ($device['type']->value) {
                    DeviceType::Mobile->value => rand(375, 480),
                    DeviceType::Tablet->value => rand(768, 1023),
                    DeviceType::Desktop->value => rand(1024, 1920),
                    default => rand(1024, 1920),
                };

                // Determine exit page
                $exitPage = $entryPage;
                $pages = [$entryPage];
                for ($p = 1; $p < $pageviewCount; $p++) {
                    $page = $this->weightedPageSelection($topPages);
                    $pages[] = $page;
                    $exitPage = $page;
                }

                $sessionId = Str::uuid();

                $sessionData = [
                    'id' => $sessionId,
                    'site_id' => $site->id,
                    'visitor_id' => $visitorId,
                    'started_at' => $sessionTime,
                    'duration' => $duration,
                    'pageviews' => $pageviewCount,
                    'is_bounce' => $isBounce,
                    'entry_page' => $entryPage,
                    'exit_page' => $exitPage,
                    'utm_source' => $traffic['source'],
                    'utm_medium' => $traffic['medium'],
                    'utm_campaign' => $traffic['campaign'],
                    'utm_content' => null,
                    'utm_term' => null,
                    'referrer' => $traffic['source'] ? "https://{$traffic['source']}.com" : null,
                    'referrer_domain' => $traffic['source'],
                    'channel' => $channel,
                    'country_code' => $location['country_code'],
                    'subdivision_code' => $location['country_code'] . '-' . $location['subdivision'],
                    'city' => $location['city'],
                    'browser' => $browserName,
                    'browser_version' => $browserVersion,
                    'os' => $osName,
                    'os_version' => $osVersion,
                    'device_type' => $device['type'],
                    'screen_width' => $screenWidth,
                ];
                $sessionBatch[] = $sessionData;

                // Store session ID mapping for pageview insertion
                $sessionIdMap[$visitorId . '|' . $sessionTime->timestamp] = $sessionId;

                // Store pageview data temporarily (need session ID after insertion)
                if (config('analytics.track_page_views', true)) {
                    $pageviewTime = $sessionTime->copy();
                    foreach ($pages as $pageIndex => $page) {
                        $pageviewBatch[] = [
                            'visitor_id' => $visitorId,  // Add visitor_id for session matching
                            'started_at' => $sessionTime,  // Add started_at for session matching
                            'site_id' => $site->id,
                            'hostname' => $site->domain,
                            'pathname' => $page,
                            'viewed_at' => $pageviewTime,
                            'is_entry' => $pageIndex === 0,
                            'is_exit' => $pageIndex === count($pages) - 1,
                        ];
                        // Increment time for next pageview
                        $pageviewTime->addSeconds(rand(5, 30));
                    }
                }
            }

            // Batch insert sessions at the end of each day
            if (!empty($sessionBatch)) {
                Session::insert($sessionBatch);

                // Now insert pageviews if we have any
                if (!empty($pageviewBatch)) {
                    $finalPageviews = [];
                    foreach ($pageviewBatch as $pv) {
                        $key = $pv['visitor_id'] . '|' . $pv['started_at']->timestamp;
                        $sessionId = $sessionIdMap[$key] ?? null;

                        if ($sessionId) {
                            $finalPageviews[] = [
                                'site_id' => $pv['site_id'],
                                'session_id' => $sessionId,
                                'hostname' => $pv['hostname'],
                                'pathname' => $pv['pathname'],
                                'viewed_at' => $pv['viewed_at'],
                                'is_entry' => $pv['is_entry'],
                                'is_exit' => $pv['is_exit'],
                            ];
                        }
                    }

                    if (!empty($finalPageviews)) {
                        Pageview::insert($finalPageviews);
                    }
                    $pageviewBatch = [];
                }

                $sessionBatch = [];
                $sessionIdMap = [];
            }

            $this->command->info("Generated sessions for {$date->format('Y-m-d')}");
        }
    }

    private function aggregateData(Site $site, int $days): void
    {
        $startDate = today()->subDays($days - 1);

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i)->toDateString();
            Artisan::call('analytics:aggregate', ['--date' => $date]);
        }

        $this->command->info('All analytics data aggregated.');
    }

    /**
     * Select an item from a weighted array.
     * Array format: [['item' => mixed, 'weight' => float], ...]
     */
    private function weightedRandom(array $items): mixed
    {
        $totalWeight = array_sum(array_column($items, 'weight'));
        $random = (mt_rand() / mt_getrandmax()) * $totalWeight;

        $cumulative = 0;
        foreach ($items as $item) {
            $cumulative += $item['weight'];
            if ($random <= $cumulative) {
                return $item;
            }
        }

        return $items[array_key_last($items)];
    }

    /**
     * Select a page with power-law distribution (few pages get most traffic).
     */
    private function weightedPageSelection(array $pages): string
    {
        // Weighted distribution: first page gets most traffic, then exponential decay
        $weights = [];
        for ($i = 0; $i < count($pages); $i++) {
            $weights[] = 1 / (($i + 1) ** 1.5);
        }

        $totalWeight = array_sum($weights);
        $random = (mt_rand() / mt_getrandmax()) * $totalWeight;

        $cumulative = 0;
        foreach ($pages as $index => $page) {
            $cumulative += $weights[$index];
            if ($random <= $cumulative) {
                return $page;
            }
        }

        return $pages[0];
    }

    private function getUserAgent(string $browser, string $os): string
    {
        $browsers = [
            'Chrome' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            ],
            'Safari' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
            ],
            'Firefox' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'Mozilla/5.0 (Android 14; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0',
            ],
            'Edge' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            ],
        ];

        $ua = $browsers[$browser] ?? $browsers['Chrome'];

        return $ua[array_rand($ua)];
    }
}
