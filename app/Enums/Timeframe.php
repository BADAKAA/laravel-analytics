<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

class Timeframe {
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
}
