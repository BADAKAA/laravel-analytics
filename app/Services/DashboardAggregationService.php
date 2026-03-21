<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\DeviceType;
use App\Models\DailyStat;
use App\Models\Pageview;
use App\Models\Session;
use Carbon\Carbon;

class DashboardAggregationService {
    private const TOP_X_RESULTS = 9;
    private const PAGE_CATEGORIES = ['top_pages', 'entry_pages', 'exit_pages'];
    private const DEFAULT_CATEGORIES = [
        'channels',
        'top_pages',
        'countries',
        'browsers',
    ];

    public function __construct(
        private SessionFilterService $filterService
    ) {
    }

    /**
     * Central method for fetching aggregated dashboard data
     */
    public function fetchAggregateData(
        int $siteId,
        Carbon $startDate,
        Carbon $endDate,
        array $requestedCategories = [],
        bool $includeMetrics = true,
        array $filters = []
    ): array {
        if (empty($requestedCategories)) {
            $requestedCategories = self::DEFAULT_CATEGORIES;
        }

        $hasFilters = !empty(array_filter($filters));

        if (!$hasFilters) {
            return $this->getAggregateFromDailyStat(
                $siteId,
                $startDate,
                $endDate,
                $requestedCategories,
                $includeMetrics
            );
        }

        return $this->getAggregateFromSessions(
            $siteId,
            $startDate,
            $endDate,
            $requestedCategories,
            $includeMetrics,
            $filters
        );
    }

    /**
     * Get aggregated data from DailyStat (fast path, no filters)
     */
    private function getAggregateFromDailyStat(
        int $siteId,
        Carbon $startDate,
        Carbon $endDate,
        array $requestedCategories,
        bool $includeMetrics
    ): array {
        $dailyStats = DailyStat::where('site_id', $siteId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('date')
            ->get();

        $response = [];

        if ($includeMetrics) {
            if ($dailyStats->isEmpty()) {
                $response['metrics'] = [
                    'visitors' => 0,
                    'visits' => 0,
                    'pageviews' => 0,
                    'bounce_rate' => 0,
                    'avg_duration' => 0,
                    'views_per_visit' => 0,
                    'chart_data' => [],
                ];
            } else {
                // Use database aggregation instead of PHP collection operations
                $metrics = DailyStat::where('site_id', $siteId)
                    ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->selectRaw('
                        SUM(visitors) as total_visitors,
                        SUM(visits) as total_visits,
                        SUM(pageviews) as total_pageviews,
                        AVG(avg_duration) as avg_duration,
                        SUM(bounce_rate * visits) / SUM(visits) as weighted_bounce_rate
                    ')
                    ->first();

                $visitors = (int) $metrics->total_visitors ?? 0;
                $visits = (int) $metrics->total_visits ?? 0;
                $pageviews = (int) $metrics->total_pageviews ?? 0;
                $avgDuration = (float) $metrics->avg_duration ?? 0;
                $bounceRate = ($visits > 0) ? round((float) $metrics->weighted_bounce_rate, 2) : 0;
                $viewsPerVisit = ($visits > 0) ? round($pageviews / $visits, 2) : 0;

                $response['metrics'] = [
                    'visitors' => $visitors,
                    'visits' => $visits,
                    'pageviews' => $pageviews,
                    'bounce_rate' => $bounceRate,
                    'avg_duration' => round($avgDuration, 2),
                    'views_per_visit' => $viewsPerVisit,
                    'chart_data' => $dailyStats->map(fn($stat) => [
                        'date' => $stat->date->toDateString(),
                        'visitors' => $stat->visitors,
                        'visits' => $stat->visits,
                        'pageviews' => $stat->pageviews,
                        'bounce_rate' => round($stat->bounce_rate, 2),
                        'avg_duration' => $stat->avg_duration,
                        'views_per_visit' => $stat->visits > 0 ? round($stat->pageviews / $stat->visits, 2) : 0,
                    ])->toArray(),
                ];
            }
        }

        // Add requested categories
        $categoryMap = [
            'channels' => 'channels_agg',
            'sources' => 'referrers_agg',
            'utm_campaigns' => 'utm_campaigns_agg',
            'top_pages' => 'top_pages_agg',
            'entry_pages' => 'entry_pages_agg',
            'exit_pages' => 'exit_pages_agg',
            'countries' => 'countries_agg',
            'regions' => 'regions_agg',
            'cities' => 'cities_agg',
            'browsers' => 'browsers_agg',
            'operating_systems' => 'os_agg',
            'devices' => 'devices_agg',
        ];

        // Define which categories need enum formatting
        $enumFormatMap = [
            'channels' => ['column' => 'channel', 'format_enum' => true],
            'devices' => ['column' => 'device_type', 'format_enum' => true],
        ];

        foreach ($requestedCategories as $category) {
            if (!isset($categoryMap[$category])) {
                continue;
            }

            $field = $categoryMap[$category];
            $aggregations = $dailyStats->pluck($field);
            // Apply limit BEFORE merging to reduce memory usage
            $data = $this->mergeAggregations($aggregations, self::TOP_X_RESULTS);

            // Apply enum formatting if needed
            if (isset($enumFormatMap[$category])) {
                $meta = $enumFormatMap[$category];
                $data = array_map(fn($item) => [
                    'name' => $this->formatCategoryValueForAggregate($item['name'], $meta['column'], $meta['format_enum']),
                    'visitors' => $item['visitors'],
                ], $data);
            }

            $response[$category] = $data;
        }

        return $response;
    }

    /**
     * Get aggregated data from Sessions (filtered path)
     */
    private function getAggregateFromSessions(
        int $siteId,
        Carbon $startDate,
        Carbon $endDate,
        array $requestedCategories,
        bool $includeMetrics,
        array $filters
    ): array {
        $baseQuery = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $baseQuery = $this->applySessionFilters($baseQuery, $filters);

        $response = [];

        // Add metrics if requested
        if ($includeMetrics) {
            $query = clone $baseQuery;
            $totalVisits = (clone $query)->count();
            $totalVisitors = (clone $query)->distinct('visitor_id')->count();
            $bouncedSessions = (clone $query)->where('is_bounce', true)->count();
            $totalPageviews = (clone $query)->sum('pageviews') ?? 0;
            $avgDuration = (clone $query)->avg('duration') ?? 0;

            // Chart data
            $chartData = $query
                ->selectRaw('DATE(started_at) as date, COUNT(*) as visits, COUNT(DISTINCT visitor_id) as visitors, SUM(pageviews) as pageviews, AVG(duration) as avg_duration, ROUND(SUM(CASE WHEN is_bounce THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as bounce_rate')
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(fn($item) => [
                    'date' => $item->date,
                    'visitors' => $item->visitors,
                    'visits' => $item->visits,
                    'pageviews' => $item->pageviews,
                    'bounce_rate' => $item->bounce_rate ?? 0,
                    'avg_duration' => round($item->avg_duration, 2),
                    'views_per_visit' => $item->visits > 0 ? round($item->pageviews / $item->visits, 2) : 0,
                ]);

            $response['metrics'] = [
                'visitors' => $totalVisitors,
                'visits' => $totalVisits,
                'pageviews' => $totalPageviews,
                'bounce_rate' => $totalVisits > 0 ? round(($bouncedSessions / $totalVisits), 2) : 0,
                'avg_duration' => round($avgDuration, 2),
                'views_per_visit' => $totalVisits > 0 ? round($totalPageviews / $totalVisits, 2) : 0,
                'chart_data' => $chartData->toArray(),
            ];
        }

        // Map categories to their query methods
        $categoryQueryMap = [
            'channels' => ['column' => 'channel', 'format_enum' => true],
            'sources' => ['column' => 'referrer_domain', 'format_enum' => false],
            'utm_campaigns' => ['column' => 'utm_campaign', 'format_enum' => false],
            'countries' => ['column' => 'country_code', 'format_enum' => false],
            'regions' => ['column' => 'subdivision_code', 'format_enum' => false],
            'cities' => ['column' => 'city', 'format_enum' => false],
            'browsers' => ['column' => 'browser', 'format_enum' => false],
            'operating_systems' => ['column' => 'os', 'format_enum' => false],
            'devices' => ['column' => 'device_type', 'format_enum' => true],
        ];

        // Collect requested page categories and query them all at once for efficiency
        $requestedPageCategories = array_filter($requestedCategories, fn($cat) => in_array($cat, self::PAGE_CATEGORIES));
        if (!empty($requestedPageCategories)) {
            $pageData = $this->getPageCategoriesData($baseQuery, $requestedPageCategories, $siteId, $startDate, $endDate, $filters);
            $response = array_merge($response, $pageData);
        }

        // Query other categories
        foreach ($requestedCategories as $category) {
            if (in_array($category, self::PAGE_CATEGORIES) || isset($response[$category])) {
                continue;
            }

            if (isset($categoryQueryMap[$category])) {
                $meta = $categoryQueryMap[$category];
                $query = clone $baseQuery;
                $data = $query
                    ->selectRaw("{$meta['column']}, COUNT(*) as visitors")
                    ->whereNotNull($meta['column'])
                    ->groupBy($meta['column'])
                    ->orderByDesc('visitors')
                    ->limit(self::TOP_X_RESULTS)
                    ->get()
                    ->map(fn($item) => [
                        'name' => $this->formatCategoryValueForAggregate($item->{$meta['column']}, $meta['column'], $meta['format_enum']),
                        'visitors' => $item->visitors,
                    ]);
                $response[$category] = $data->toArray();
            }
        }

        return $response;
    }

    /**
     * Format category value for aggregation (handles enum conversions)
     */
    private function formatCategoryValueForAggregate($value, string $column, bool $formatEnum = false): string {
        if ($value === null) {
            return 'Unknown';
        }

        if ($formatEnum && $column === 'channel') {
            try {
                return Channel::from((int) $value)->label();
            } catch (\Throwable) {
                return "Unknown Channel {$value}";
            }
        }

        if ($formatEnum && $column === 'device_type') {
            try {
                return DeviceType::from((int) $value)->label();
            } catch (\Throwable) {
                return "Unknown Device {$value}";
            }
        }

        return (string) $value;
    }

    /**
     * Get all requested page categories in optimized batch queries
     * This queries entry_pages, exit_pages, and top_pages in fewer database calls
     */
    private function getPageCategoriesData(
        $baseQuery,
        array $requestedPageCategories,
        int $siteId,
        Carbon $startDate,
        Carbon $endDate,
        array $filters
    ): array {
        $trackPageViews = config('analytics.track_page_views', true);

        if ($trackPageViews) {
            // Batch query: fetch all page types in one optimized query structure
            return $this->getPageCategoriesFromPageviews(
                $requestedPageCategories,
                $siteId,
                $startDate,
                $endDate,
                $filters
            );
        }

        // Fallback: use Session model entry_page/exit_page columns
        return $this->getPageCategoriesFromSessions(
            $baseQuery,
            $requestedPageCategories
        );
    }

    /**
     * Query page categories from Pageview table with pre-calculated aggregations
     * Batches all page types into efficient grouped queries
     */
    private function getPageCategoriesFromPageviews(
        array $requestedPageCategories,
        int $siteId,
        Carbon $startDate,
        Carbon $endDate,
        array $filters
    ): array {
        $result = [];

        // Query entry pages if requested
        if (in_array('entry_pages', $requestedPageCategories)) {
            $query = Pageview::where('pageviews.site_id', $siteId)
                ->whereBetween('viewed_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                ->where('is_entry', true)
                ->join('sessions', 'pageviews.session_id', '=', 'sessions.id');

            $query = $this->applySessionFilters($query, $filters, 'pageview');

            $result['entry_pages'] = $query
                ->selectRaw('pageviews.pathname as page, COUNT(*) as visitors')
                ->groupBy('pageviews.pathname')
                ->orderByDesc('visitors')
                ->limit(self::TOP_X_RESULTS)
                ->get()
                ->map(fn($item) => [
                    'name' => $item->page,
                    'visitors' => $item->visitors,
                ])
                ->toArray();
        }

        // Query exit pages if requested
        if (in_array('exit_pages', $requestedPageCategories)) {
            $query = Pageview::where('pageviews.site_id', $siteId)
                ->whereBetween('viewed_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                ->whereRaw('pageviews.pathname = sessions.exit_page')
                ->join('sessions', 'pageviews.session_id', '=', 'sessions.id');

            $query = $this->applySessionFilters($query, $filters, 'pageview');

            $result['exit_pages'] = $query
                ->selectRaw('pageviews.pathname as page, COUNT(*) as visitors')
                ->groupBy('pageviews.pathname')
                ->orderByDesc('visitors')
                ->limit(self::TOP_X_RESULTS)
                ->get()
                ->map(fn($item) => [
                    'name' => $item->page,
                    'visitors' => $item->visitors,
                ])
                ->toArray();
        }

        // Query top pages if requested (all pageviews, not just entry/exit)
        if (in_array('top_pages', $requestedPageCategories)) {
            $query = Pageview::where('pageviews.site_id', $siteId)
                ->whereBetween('viewed_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                ->join('sessions', 'pageviews.session_id', '=', 'sessions.id');

            $query = $this->applySessionFilters($query, $filters, 'pageview');

            $result['top_pages'] = $query
                ->selectRaw('pageviews.pathname as page, COUNT(*) as visitors')
                ->groupBy('pageviews.pathname')
                ->orderByDesc('visitors')
                ->limit(self::TOP_X_RESULTS)
                ->get()
                ->map(fn($item) => [
                    'name' => $item->page,
                    'visitors' => $item->visitors,
                ])
                ->toArray();
        }

        return $result;
    }

    /**
     * Query page categories from Session model (when pageviews are not tracked)
     * Uses pre-aggregated entry_page and exit_page columns
     */
    private function getPageCategoriesFromSessions(
        $baseQuery,
        array $requestedPageCategories
    ): array {
        $result = [];

        if (in_array('entry_pages', $requestedPageCategories)) {
            $query = clone $baseQuery;
            $result['entry_pages'] = $query
                ->selectRaw('entry_page as page, COUNT(*) as visitors')
                ->whereNotNull('entry_page')
                ->groupBy('entry_page')
                ->orderByDesc('visitors')
                ->limit(self::TOP_X_RESULTS)
                ->get()
                ->map(fn($item) => [
                    'name' => $item->page,
                    'visitors' => $item->visitors,
                ])
                ->toArray();
        }

        if (in_array('exit_pages', $requestedPageCategories)) {
            $query = clone $baseQuery;
            $result['exit_pages'] = $query
                ->selectRaw('exit_page as page, COUNT(*) as visitors')
                ->whereNotNull('exit_page')
                ->groupBy('exit_page')
                ->orderByDesc('visitors')
                ->limit(self::TOP_X_RESULTS)
                ->get()
                ->map(fn($item) => [
                    'name' => $item->page,
                    'visitors' => $item->visitors,
                ])
                ->toArray();
        }

        if (in_array('top_pages', $requestedPageCategories)) {
            $query = clone $baseQuery;
            $result['top_pages'] = $query
                ->selectRaw('entry_page as page, COUNT(*) as visitors')
                ->whereNotNull('entry_page')
                ->groupBy('entry_page')
                ->orderByDesc('visitors')
                ->limit(self::TOP_X_RESULTS)
                ->get()
                ->map(fn($item) => [
                    'name' => $item->page,
                    'visitors' => $item->visitors,
                ])
                ->toArray();
        }

        return $result;
    }

    public function applySessionFilters($query, array $filters, ?string $tablePrefix = null): mixed {
        return $this->filterService->applyFilters($query, $filters, $tablePrefix);
    }

    private function mergeAggregations($aggregations, ?int $limit = null) {
        $merged = [];
        foreach ($aggregations as $agg) {
            if (!is_array($agg)) continue;

            foreach ($agg as $item) {
                if (!is_array($item) || !isset($item['key'])) continue;

                $key = (string) $item['key'];
                // Use 'pageviews' if available (for top_pages), otherwise 'visits' (session count)
                $count = (int) ($item['pageviews'] ?? $item['visits'] ?? $item['visitors'] ?? 0);
                $merged[$key] = ($merged[$key] ?? 0) + $count;
            }
        }

        arsort($merged);

        $result = array_map(fn($key, $count) => ['name' => $key, 'visitors' => $count], array_keys($merged), array_values($merged));

        // Apply limit if specified to reduce memory usage
        if ($limit !== null) {
            return array_slice($result, 0, $limit);
        }

        return $result;
    }
}
