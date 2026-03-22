<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\DeviceType;
use App\Models\DailyStat;
use App\Models\Pageview;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DashboardAggregationService {
    private const TOP_X_RESULTS = 9;
    private const PAGE_CATEGORIES = ['top_pages', 'entry_pages', 'exit_pages'];
    private const DEFAULT_CATEGORIES = [
        'channels',
        'top_pages',
        'countries',
        'browsers',
    ];


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
        $todayCarbon = now();
        $today = $todayCarbon->toDateString();
        $startDateString = $startDate->toDateString();
        $endDateString = $endDate->toDateString();
        $includesToday = $startDateString <= $today && $endDateString >= $today;

        if ($includesToday) {
            DailyStatService::updateCurrentDay($siteId);
        }

        // Keep DailyStat reads for completed days and merge current day from live sessions.
        $historicalEndDate = $includesToday
            ? min($endDateString, $todayCarbon->copy()->subDay()->toDateString())
            : $endDateString;

        $dailyStats = collect();
        if ($startDateString <= $historicalEndDate) {
            $dailyStats = DailyStat::where('site_id', $siteId)
                ->whereDate('date', '>=', $startDateString)
                ->whereDate('date', '<=', $historicalEndDate)
                ->orderBy('date')
                ->get();
        }

        $response = [];

        if ($includeMetrics) {
            $visitors = (int) $dailyStats->sum('visitors');
            $visits = (int) $dailyStats->sum('visits');
            $pageviews = (int) $dailyStats->sum('pageviews');
            $bounceCount = (int) $dailyStats->sum('bounce_count');
            $totalDuration = (float) $dailyStats->sum(fn($stat) => (int) ($stat->avg_duration ?? 0) * (int) $stat->visits);

            $chartData = $dailyStats->map(fn($stat) => [
                'date' => $stat->date->toDateString(),
                'visitors' => $stat->visitors,
                'visits' => $stat->visits,
                'pageviews' => $stat->pageviews,
                'bounce_rate' => round($stat->bounce_rate ?? 0, 2),
                'avg_duration' => $stat->avg_duration,
                'views_per_visit' => $stat->visits > 0 ? round($stat->pageviews / $stat->visits, 2) : 0,
            ])->toArray();

            if ($includesToday) {
                $todayMetrics = $this->calculateSessionMetrics(
                    Session::where('site_id', $siteId)
                        ->whereBetween('started_at', [$todayCarbon->copy()->startOfDay(), $todayCarbon->copy()->endOfDay()])
                );

                $visitors += $todayMetrics['visitors'];
                $visits += $todayMetrics['visits'];
                $pageviews += $todayMetrics['pageviews'];
                $bounceCount += $todayMetrics['bounce_count'];
                $totalDuration += $todayMetrics['total_duration'];
                $chartData = array_merge($chartData, $todayMetrics['chart_data']);
            }

            usort($chartData, fn($a, $b) => strcmp($a['date'], $b['date']));

            $response['metrics'] = $this->buildMetricsResponse(
                $visitors,
                $visits,
                $pageviews,
                $bounceCount,
                $totalDuration,
                $chartData
            );
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
            if (!isset($categoryMap[$category])) continue;

            $field = $categoryMap[$category];
            $aggregations = $dailyStats->pluck($field);
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

        if ($includesToday) {
            $todayAggregate = $this->getAggregateFromSessions(
                $siteId,
                Carbon::parse($today),
                Carbon::parse($today),
                $requestedCategories,
                false,
                []
            );

            foreach ($requestedCategories as $category) {
                if (!isset($todayAggregate[$category])) continue;
                $response[$category] = $this->mergeNamedVisitors(
                    $response[$category] ?? [],
                    $todayAggregate[$category],
                    self::TOP_X_RESULTS
                );
            }
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

        $baseQuery = SessionFilters::apply($baseQuery, $filters);

        $response = [];

        // Add metrics if requested
        if ($includeMetrics) {
            $metrics = $this->calculateSessionMetrics($baseQuery);
            $response['metrics'] = $this->buildMetricsResponse(
                $metrics['visitors'],
                $metrics['visits'],
                $metrics['pageviews'],
                $metrics['bounce_count'],
                $metrics['total_duration'],
                $metrics['chart_data']
            );
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
                $response[$category] = $this->querySessionCategory(
                    $baseQuery,
                    $meta['column'],
                    $meta['format_enum']
                );
            }
        }

        return $response;
    }

    /**
     * Format category value for aggregation (handles enum conversions)
     */
    private function formatCategoryValueForAggregate($value, string $column, bool $formatEnum = false): string {
        if ($value === null) return 'Unknown';

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($formatEnum && $column === 'channel') {
            try {
                return Channel::from((int) $value)->label();
            } catch (\Throwable) {
                $display = is_scalar($value) ? (string) $value : 'unknown';
                return "Unknown Channel {$display}";
            }
        }

        if ($formatEnum && $column === 'device_type') {
            try {
                return DeviceType::from((int) $value)->label();
            } catch (\Throwable) {
                $display = is_scalar($value) ? (string) $value : 'unknown';
                return "Unknown Device {$display}";
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
        $requested = array_values(array_intersect($requestedPageCategories, self::PAGE_CATEGORIES));
        if (empty($requested)) {
            return [];
        }

        $requestedSet = array_fill_keys($requested, true);

        $query = Pageview::where('pageviews.site_id', $siteId)
            ->whereBetween('viewed_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->join('sessions', 'pageviews.session_id', '=', 'sessions.id');

        $query = SessionFilters::apply($query, $filters, 'pageview');

        $selectParts = ['pageviews.pathname as page'];
        if (isset($requestedSet['top_pages'])) {
            $selectParts[] = 'COUNT(*) as top_visitors';
        }
        if (isset($requestedSet['entry_pages'])) {
            $selectParts[] = 'SUM(CASE WHEN pageviews.is_entry THEN 1 ELSE 0 END) as entry_visitors';
        }
        if (isset($requestedSet['exit_pages'])) {
            $selectParts[] = 'SUM(CASE WHEN pageviews.pathname = sessions.exit_page THEN 1 ELSE 0 END) as exit_visitors';
        }

        $rows = $query
            ->selectRaw(implode(', ', $selectParts))
            ->groupBy('pageviews.pathname')
            ->get();

        $result = [];
        if (isset($requestedSet['top_pages'])) {
            $result['top_pages'] = $this->buildPageCategoryFromAggregatedRows($rows, 'top_visitors');
        }
        if (isset($requestedSet['entry_pages'])) {
            $result['entry_pages'] = $this->buildPageCategoryFromAggregatedRows($rows, 'entry_visitors');
        }
        if (isset($requestedSet['exit_pages'])) {
            $result['exit_pages'] = $this->buildPageCategoryFromAggregatedRows($rows, 'exit_visitors');
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
        $requested = array_values(array_intersect($requestedPageCategories, self::PAGE_CATEGORIES));
        if (empty($requested)) {
            return [];
        }

        $requestedSet = array_fill_keys($requested, true);
        $result = [];

        if (isset($requestedSet['entry_pages']) || isset($requestedSet['top_pages'])) {
            $entryData = $this->queryPageCategoryFromSessions($baseQuery, 'entry_page');
            if (isset($requestedSet['entry_pages'])) {
                $result['entry_pages'] = $entryData;
            }
            if (isset($requestedSet['top_pages'])) {
                $result['top_pages'] = $entryData;
            }
        }

        if (isset($requestedSet['exit_pages'])) {
            $result['exit_pages'] = $this->queryPageCategoryFromSessions($baseQuery, 'exit_page');
        }

        return $result;
    }


    private function calculateSessionMetrics($query): array {
        $summary = (clone $query)
            ->selectRaw('COUNT(*) as visits')
            ->selectRaw('COUNT(DISTINCT visitor_id) as visitors')
            ->selectRaw('COALESCE(SUM(pageviews), 0) as pageviews')
            ->selectRaw('COALESCE(SUM(duration), 0) as total_duration')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_bounce THEN 1 ELSE 0 END), 0) as bounce_count')
            ->first();

        $visits = (int) ($summary->visits ?? 0);
        $visitors = (int) ($summary->visitors ?? 0);
        $pageviews = (int) ($summary->pageviews ?? 0);
        $bounceCount = (int) ($summary->bounce_count ?? 0);
        $totalDuration = (float) ($summary->total_duration ?? 0);

        $chartData = (clone $query)
            ->selectRaw('DATE(started_at) as date, COUNT(*) as visits, COUNT(DISTINCT visitor_id) as visitors, COALESCE(SUM(pageviews), 0) as pageviews, COALESCE(SUM(duration), 0) as total_duration, COALESCE(SUM(CASE WHEN is_bounce THEN 1 ELSE 0 END), 0) as bounce_count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($item) => [
                'date' => $item->date,
                'visitors' => (int) $item->visitors,
                'visits' => (int) $item->visits,
                'pageviews' => (int) $item->pageviews,
                'bounce_rate' => (int) $item->visits > 0 ? round(((int) $item->bounce_count / (int) $item->visits) * 100, 2) : 0,
                'avg_duration' => (int) $item->visits > 0 ? round((float) $item->total_duration / (int) $item->visits, 2) : 0,
                'views_per_visit' => (int) $item->visits > 0 ? round((int) $item->pageviews / (int) $item->visits, 2) : 0,
            ])
            ->toArray();

        return [
            'visitors' => $visitors,
            'visits' => $visits,
            'pageviews' => $pageviews,
            'bounce_count' => $bounceCount,
            'total_duration' => $totalDuration,
            'chart_data' => $chartData,
        ];
    }

    private function buildMetricsResponse(
        int $visitors,
        int $visits,
        int $pageviews,
        int $bounceCount,
        float $totalDuration,
        array $chartData
    ): array {
        return [
            'visitors' => $visitors,
            'visits' => $visits,
            'pageviews' => $pageviews,
            'bounce_rate' => $visits > 0 ? round(($bounceCount / $visits) * 100, 2) : 0,
            'avg_duration' => $visits > 0 ? round($totalDuration / $visits, 2) : 0,
            'views_per_visit' => $visits > 0 ? round($pageviews / $visits, 2) : 0,
            'chart_data' => $chartData,
        ];
    }

    private function buildPageCategoryFromAggregatedRows(
        Collection $rows,
        string $countKey
    ): array {
        return $rows
            ->map(fn($item) => [
                'name' => $item->page,
                'visitors' => (int) ($item->{$countKey} ?? 0),
            ])
            ->filter(fn($item) => $item['visitors'] > 0)
            ->sortByDesc('visitors')
            ->take(self::TOP_X_RESULTS)
            ->values()
            ->toArray();
    }

    private function querySessionCategory($baseQuery, string $column, bool $formatEnum = false): array {
        return (clone $baseQuery)
            ->selectRaw("{$column}, COUNT(*) as visitors")
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get()
            ->map(fn($item) => [
                'name' => $this->formatCategoryValueForAggregate($item->{$column}, $column, $formatEnum),
                'visitors' => $item->visitors,
            ])
            ->toArray();
    }

    private function queryPageCategoryFromSessions($baseQuery, string $column): array {
        return (clone $baseQuery)
            ->selectRaw("{$column} as page, COUNT(*) as visitors")
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get()
            ->map(fn($item) => [
                'name' => $item->page,
                'visitors' => $item->visitors,
            ])
            ->toArray();
    }

    private function mergeNamedVisitors(array $existing, array $incoming, int $limit): array {
        $merged = [];

        foreach ($existing as $item) {
            if (!is_array($item) || !isset($item['name'])) continue;

            $name = (string) $item['name'];
            $merged[$name] = ($merged[$name] ?? 0) + (int) ($item['visitors'] ?? 0);
        }

        foreach ($incoming as $item) {
            if (!is_array($item) || !isset($item['name'])) continue;
            $name = (string) $item['name'];
            $merged[$name] = ($merged[$name] ?? 0) + (int) ($item['visitors'] ?? 0);
        }

        arsort($merged);

        return array_slice(
            array_map(
                fn($name, $count) => ['name' => $name, 'visitors' => $count],
                array_keys($merged),
                array_values($merged)
            ),
            0,
            $limit
        );
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
        if ($limit !== null) return array_slice($result, 0, $limit);

        return $result;
    }
}
