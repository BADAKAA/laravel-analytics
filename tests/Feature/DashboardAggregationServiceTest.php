<?php

use App\Enums\Channel;
use App\Models\DailyStat;
use App\Models\Session;
use App\Models\Site;
use App\Services\DashboardAggregationService;
use Carbon\Carbon;

it('merges live current-day session data into unfiltered daily-stat aggregates', function () {
    Carbon::setTestNow('2026-03-22 12:00:00');

    try {
        $site = Site::create([
            'domain' => 'example.test',
            'name' => 'Example',
            'timezone' => 'UTC',
            'is_public' => false,
        ]);

        DailyStat::create([
            'site_id' => $site->id,
            'date' => '2026-03-21',
            'visitors' => 8,
            'visits' => 10,
            'pageviews' => 20,
            'views_per_visit' => 2.0,
            'bounce_count' => 5,
            'avg_duration' => 100,
            'channels_agg' => [
                ['key' => Channel::Direct->value, 'visits' => 10],
            ],
            'top_pages_agg' => [
                ['key' => '/home', 'visits' => 10],
            ],
            'countries_agg' => [
                ['key' => 'US', 'visits' => 10],
            ],
            'browsers_agg' => [
                ['key' => 'Chrome', 'visits' => 10],
            ],
        ]);

        expect(DailyStat::where('site_id', $site->id)->count())->toBe(1);

        Session::create([
            'site_id' => $site->id,
            'visitor_id' => 'visitor-1',
            'started_at' => '2026-03-22 08:00:00',
            'duration' => 60,
            'pageviews' => 1,
            'is_bounce' => true,
            'entry_page' => '/home',
            'exit_page' => '/home',
            'channel' => Channel::Direct->value,
        ]);

        Session::create([
            'site_id' => $site->id,
            'visitor_id' => 'visitor-2',
            'started_at' => '2026-03-22 09:00:00',
            'duration' => 120,
            'pageviews' => 3,
            'is_bounce' => false,
            'entry_page' => '/pricing',
            'exit_page' => '/contact',
            'channel' => Channel::OrganicSearch->value,
        ]);

        $service = app(DashboardAggregationService::class);

        $historicalOnly = $service->fetchAggregateData(
            $site->id,
            Carbon::parse('2026-03-21'),
            Carbon::parse('2026-03-21'),
            ['channels'],
            true,
            []
        );

        expect($historicalOnly['metrics']['visits'])->toBe(10);

        $result = $service->fetchAggregateData(
            $site->id,
            Carbon::parse('2026-03-21'),
            Carbon::parse('2026-03-22'),
            ['channels'],
            true,
            []
        );

        expect($result['metrics']['visits'])->toBe(12)
            ->and($result['metrics']['visitors'])->toBe(10)
            ->and($result['metrics']['pageviews'])->toBe(24)
            ->and($result['metrics']['bounce_rate'])->toBe(50.0)
            ->and($result['metrics']['avg_duration'])->toBe(98.33)
            ->and($result['metrics']['views_per_visit'])->toBe(2.0)
            ->and($result['metrics']['chart_data'])->toHaveCount(2)
            ->and(array_column($result['metrics']['chart_data'], 'date'))->toContain('2026-03-22');

        expect($result['channels'])->toContain([
            'name' => 'Direct',
            'visitors' => 11,
        ]);
    } finally {
        Carbon::setTestNow();
    }
});
