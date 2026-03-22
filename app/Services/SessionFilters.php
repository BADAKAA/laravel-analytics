<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\DeviceType;

class SessionFilters
{
    /**
     * Apply filters to session or pageview queries with proper table prefix support
     */
    public static function apply($query, array $filters, ?string $tablePrefix = null): mixed
    {
        $prefix = $tablePrefix === 'pageview' ? 'sessions.' : '';

        // Handle enum-based filters
        if (!empty($filters['channel'])) {
            $channelEnum = Channel::fromLabel($filters['channel']);
            if ($channelEnum !== null) {
                $query->where("{$prefix}channel", $channelEnum->value);
            }
        }

        if (!empty($filters['device_type'])) {
            $deviceEnum = DeviceType::fromLabel($filters['device_type']);
            if ($deviceEnum !== null) {
                $query->where("{$prefix}device_type", $deviceEnum->value);
            }
        }

        // Handle page filter (requires special table prefix logic)
        if (!empty($filters['page']) && $tablePrefix === 'pageview') {
            $query->where('pageviews.pathname', $filters['page']);
        }

        $filterMapping = [
            'country' => 'country_code',
            'region' => 'subdivision_code',
            'city' => null,
            'browser' => null,
            'os' => null,
            'entry_page' => null,
            'exit_page' => null,
            'referrer_domain' => null,
            'utm_campaign' => null,
        ];

        foreach ($filterMapping as $filterKey => $column) {
            if (!empty($filters[$filterKey])) {
                $column ??= $filterKey;
                $query->where("{$prefix}{$column}", $filters[$filterKey]);
            }
        }

        return $query;
    }

    public static function parseFromRequest($request): array
    {
        return [
            'channel' => $request->query('filter_channel'),
            'country' => $request->query('filter_country'),
            'region' => $request->query('filter_region'),
            'city' => $request->query('filter_city'),
            'device_type' => $request->query('filter_device_type'),
            'browser' => $request->query('filter_browser'),
            'os' => $request->query('filter_os'),
            'page' => $request->query('filter_page'),
            'entry_page' => $request->query('filter_entry_page'),
            'exit_page' => $request->query('filter_exit_page'),
            'referrer_domain' => $request->query('filter_referrer_domain'),
            'utm_campaign' => $request->query('filter_utm_campaign'),
        ];
    }
}
