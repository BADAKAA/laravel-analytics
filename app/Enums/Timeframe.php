<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

class Timeframe {

    private const REALTIME_INTERVAL_MINUTES = 30;

    private const GRANULARITIES = [
        'today' => ['hour', 'day'],
        'yesterday' => ['hour', 'day'],
        'realtime' => ['minute'],
        'yesterday_24h' => ['hour', 'day'],
        '7_days' => ['day', 'week'],
        '28_days' => ['day', 'week'],
        '90_days' => ['day', 'week'],
        'month_to_date' => ['day', 'week'],
        'last_month' => ['day', 'week'],
        'year_to_date' => ['month', 'week', 'day'],
        'last_12_months' => ['month', 'week', 'day'],
        'all_time' => ['month', 'week', 'day'],
    ];


    public static function convert(string $timeframe): ?Carbon {
        return match ($timeframe) {
            'today' => Carbon::now()->startOfDay(),
            'yesterday' => Carbon::yesterday()->startOfDay(),
            'realtime' => Carbon::now()->subMinutes(self::REALTIME_INTERVAL_MINUTES),
            'yesterday_24h' => Carbon::yesterday()->startOfDay(),
            '7_days' => Carbon::now()->subDays(7),
            '28_days' => Carbon::now()->subDays(28),
            '90_days' => Carbon::now()->subDays(90),
            'month_to_date' => Carbon::now()->startOfMonth(),
            'last_month' => Carbon::now()->subMonth()->startOfMonth(),
            'year_to_date' => Carbon::now()->startOfYear(),
            'last_12_months' => Carbon::now()->subMonths(12),
            'all_time' => Carbon::minValue(),
            default => null,
        };
    }

    public static function getAllowedGranularities(string $timeframe): array {
        return self::GRANULARITIES[$timeframe] ?? ['day'];
    }

    public static function getDefaultGranularity(string $timeframe): string {
        $granularities = self::getAllowedGranularities($timeframe);
        return $granularities[0] ?? 'day';
    }

    public static function getAllGranularitiesByTimeframe(): array {
        return self::GRANULARITIES;
    }
}
