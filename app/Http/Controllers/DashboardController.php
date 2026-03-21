<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use App\Enums\DeviceType;
use App\Models\DailyStat;
use App\Models\Pageview;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class DashboardController extends Controller
{
    private const TOP_X_RESULTS = 9;
    private const REALTIME_INTERVAL_MINUTES = 30;
    private const DEFAULT_TIMEFRAME = '28_days';
    private const DEFAULT_CATEGORIES = [
        'channels',
        'top_pages',
        'countries',
        'browsers',
    ];

    public function index(Request $request)
    {
        $user = Auth::user();
        $sites = $user->sites()->get(['sites.id', 'name', 'domain', 'timezone']);

        $siteId = $request->query('site_id') ? (int) $request->query('site_id') : $sites->first()?->id;
        if (!$siteId) abort(404, 'No sites found');

        $timeframe = $request->query('timeframe', self::DEFAULT_TIMEFRAME);
        $customStart = $request->query('date_from');
        $customEnd = $request->query('date_to');

        $endDate = Carbon::now();
        $startDate = match ($timeframe) {
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
            default => $customStart ? Carbon::parse($customStart) : Carbon::now()->subDays(self::DEFAULT_TIMEFRAME),
        };

        if ($customEnd) $endDate = Carbon::parse($customEnd);

        $unfilteredData = $this->fetchAggregateData(
            $siteId,
            $startDate,
            $endDate
        );

        return inertia('dashboard/Dashboard', [
            'sites' => $sites,
            'selectedSiteId' => $siteId,
            'timeframe' => $timeframe,
            'dateRange' => [
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'unfiltered_data' => $unfilteredData,
        ]);
    }

    public function getLiveVisitors(Request $request)
    {
        $siteId = $request->query('site_id');
        
        if (!$siteId) {
            return response()->json(['count' => 0]);
        }

        $count = Session::where('site_id', $siteId)
            ->where('started_at', '>=', Carbon::now()->subMinutes(self::REALTIME_INTERVAL_MINUTES))
            ->distinct('visitor_id')
            ->count('visitor_id');

        return response()->json(['count' => $count]);
    }

    /**
     * Consolidated dashboard data endpoint - returns requested categories and optionally metrics
     * POST /api/dashboard/aggregate
     * 
     * Request body:
     * {
     *   "site_id": 1,
     *   "date_from": "2026-01-01",
     *   "date_to": "2026-03-21",
     *   "categories": ["channels", "sources", "countries", "browsers"],
     *   "include_metrics": true,
     *   "filters": {
     *     "channel": "organic",
     *     "country": "US"
     *   }
     * }
     */
    public function getAggregateData(Request $request)
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'categories' => ['sometimes', 'array'], // Changed from 'required' to 'sometimes' to allow empty arrays
            'categories.*' => ['string'],
            'include_metrics' => ['boolean'],
            'filters' => ['array'],
            'filters.*' => ['nullable', 'string'],
        ]);

        $siteId = $validated['site_id'];
        $startDate = Carbon::parse($validated['date_from']);
        $endDate = Carbon::parse($validated['date_to']);
        $requestedCategories = $validated['categories'];
        $includeMetrics = $validated['include_metrics'] ?? false;
        $filters = $validated['filters'] ?? [];

        $data = $this->fetchAggregateData(
            $siteId,
            $startDate,
            $endDate,
            $requestedCategories,
            $includeMetrics,
            $filters
        );

        return response()->json($data);
    }

    /**
     * Central method for fetching aggregated dashboard data
     * Used by both index() and getAggregateData() to ensure consistent data structure
     */
    private function fetchAggregateData(
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

        // Check if we can use unfiltered DailyStat data
        $hasFilters = !empty(array_filter($filters));

        if (!$hasFilters) {
            // Use fast DailyStat aggregation
            return $this->getAggregateFromDailyStat(
                $siteId,
                $startDate,
                $endDate,
                $requestedCategories,
                $includeMetrics
            );
        } else {
            // Use filtered Session data
            return $this->getAggregateFromSessions(
                $siteId,
                $startDate,
                $endDate,
                $requestedCategories,
                $includeMetrics,
                $filters
            );
        }
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

        // Add metrics if requested
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
                $visitors = $dailyStats->sum('visitors') ?? 0;
                $visits = $dailyStats->sum('visits') ?? 0;
                $pageviews = $dailyStats->sum('pageviews') ?? 0;
                $avgDuration = $dailyStats->avg('avg_duration') ?? 0;
                $bounceRate = ($visits > 0) ? round(($dailyStats->sum(function ($stat) { return $stat->bounce_rate * $stat->visits; }) / $visits), 2) : 0;
                $viewsPerVisit = ($visits > 0) ? round($pageviews / $visits, 2) : 0;

                $response['metrics'] = [
                    'visitors' => $visitors,
                    'visits' => $visits,
                    'pageviews' => $pageviews,
                    'bounce_rate' => $bounceRate,
                    'avg_duration' => round($avgDuration, 2),
                    'views_per_visit' => $viewsPerVisit,
                    'chart_data' => $dailyStats->map(fn ($stat) => [
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
            if (!isset($categoryMap[$category])) continue;

            $field = $categoryMap[$category];
            $aggregations = $dailyStats->pluck($field);
            $data = $this->mergeAggregations($aggregations);
            
            // Apply enum formatting if needed
            if (isset($enumFormatMap[$category])) {
                $meta = $enumFormatMap[$category];
                $data = array_map(fn ($item) => [
                    'name' => $this->formatCategoryValueForAggregate($item['name'], $meta['column'], $meta['format_enum']),
                    'visitors' => $item['visitors'],
                ], $data);
            }
            
            $response[$category] = array_slice($data, 0, self::TOP_X_RESULTS);
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
                ->map(fn ($item) => [
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

        foreach ($requestedCategories as $category) {
            if (in_array($category, ['top_pages', 'entry_pages', 'exit_pages'])) {
                $response[$category] = $this->getPageCategoryData($baseQuery, $category, $filters);
            } elseif (isset($categoryQueryMap[$category])) {
                $meta = $categoryQueryMap[$category];
                $query = clone $baseQuery;
                $data = $query
                    ->selectRaw("{$meta['column']}, COUNT(*) as visitors")
                    ->whereNotNull($meta['column'])
                    ->groupBy($meta['column'])
                    ->orderByDesc('visitors')
                    ->limit(self::TOP_X_RESULTS)
                    ->get()
                    ->map(fn ($item) => [
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
    private function formatCategoryValueForAggregate($value, string $column, bool $formatEnum = false): string
    {
        if ($value === null) {
            return 'Unknown';
        }

        if ($formatEnum && $column === 'channel') {
            return $this->getChannelLabel($value);
        }

        if ($formatEnum && $column === 'device_type') {
            return $this->getDeviceLabel($value);
        }

        return (string) $value;
    }

    /**
     * Get page category data (top, entry, or exit pages)
     */
    private function getPageCategoryData($baseQuery, string $type, array $filters): array
    {
        $trackPageViews = config('analytics.track_page_views', true);

        if ($trackPageViews) {
            $query = Pageview::where('pageviews.site_id', $baseQuery->getBindings()[0] ?? null)
                ->whereBetween('viewed_at', [
                    $baseQuery->getBindings()[1] ?? Carbon::now()->subDays(28),
                    $baseQuery->getBindings()[2] ?? Carbon::now()
                ])
                ->join('sessions', 'pageviews.session_id', '=', 'sessions.id');

            if ($type === 'entry_pages') {
                $query->where('is_entry', true);
            } elseif ($type === 'exit_pages') {
                $query->whereRaw('pageviews.pathname = sessions.exit_page');
            }

            $query = $this->applySessionFilters($query, $filters, 'pageview');

            return $query
                ->selectRaw('pageviews.pathname as page, COUNT(*) as visitors')
                ->groupBy('pageviews.pathname')
                ->orderByDesc('visitors')
                ->limit(self::TOP_X_RESULTS)
                ->get()
                ->map(fn ($item) => [
                    'name' => $item->page,
                    'visitors' => $item->visitors,
                ])
                ->toArray();
        } else {
            $column = match ($type) {
                'entry_pages' => 'entry_page',
                'exit_pages' => 'exit_page',
                default => 'entry_page',
            };

            return $baseQuery
                ->selectRaw("{$column} as page, COUNT(*) as visitors")
                ->whereNotNull($column)
                ->groupBy($column)
                ->orderByDesc('visitors')
                ->limit(self::TOP_X_RESULTS)
                ->get()
                ->map(fn ($item) => [
                    'name' => $item->page,
                    'visitors' => $item->visitors,
                ])
                ->toArray();
        }
    }

    /**
     * Apply filters to session queries
     */
    private function applySessionFilters($query, array $filters, ?string $tablePrefix = null): mixed
    {
        $prefix = $tablePrefix === 'pageview' ? 'sessions.' : '';

        if (!empty($filters['channel'])) {
            $channelValue = $this->getChannelValue($filters['channel']);
            if ($channelValue !== null) {
                $query->where("{$prefix}channel", $channelValue);
            }
        }
        if (!empty($filters['country'])) {
            $query->where("{$prefix}country_code", $filters['country']);
        }
        if (!empty($filters['region'])) {
            $query->where("{$prefix}subdivision_code", $filters['region']);
        }
        if (!empty($filters['city'])) {
            $query->where("{$prefix}city", $filters['city']);
        }
        if (!empty($filters['device_type'])) {
            $deviceValue = $this->getDeviceValue($filters['device_type']);
            if ($deviceValue !== null) {
                $query->where("{$prefix}device_type", $deviceValue);
            }
        }
        if (!empty($filters['browser'])) {
            $query->where("{$prefix}browser", $filters['browser']);
        }
        if (!empty($filters['os'])) {
            $query->where("{$prefix}os", $filters['os']);
        }
        if (!empty($filters['page'])) {
            if ($tablePrefix === 'pageview') {
                $query->where('pageviews.pathname', $filters['page']);
            }
        }
        if (!empty($filters['entry_page'])) {
            $query->where("{$prefix}entry_page", $filters['entry_page']);
        }
        if (!empty($filters['exit_page'])) {
            $query->where("{$prefix}exit_page", $filters['exit_page']);
        }
        if (!empty($filters['referrer_domain'])) {
            $query->where("{$prefix}referrer_domain", $filters['referrer_domain']);
        }
        if (!empty($filters['utm_campaign'])) {
            $query->where("{$prefix}utm_campaign", $filters['utm_campaign']);
        }

        return $query;
    }

    /**
     * Merge aggregations from multiple days and sort by count
     */
    private function mergeAggregations($aggregations, $aggregationType = 'default')
    {
        $merged = [];
        foreach ($aggregations as $agg) {
            if (is_array($agg)) {
                foreach ($agg as $item) {
                    // Handle structured aggregations (objects with 'key' and metric fields)
                    if (is_array($item) && isset($item['key'])) {
                        $key = (string) $item['key'];
                        // Use 'pageviews' if available (for top_pages), otherwise 'visits' (session count)
                        $count = (int) ($item['pageviews'] ?? $item['visits'] ?? $item['visitors'] ?? 0);
                        $merged[$key] = ($merged[$key] ?? 0) + $count;
                    } else {
                        // Handle flat key-value pairs (legacy format)
                        if (is_array($item)) {
                            continue;
                        }
                        $numericCount = is_numeric($item) ? (int) $item : 0;
                        $merged[$item] = ($merged[$item] ?? 0) + $numericCount;
                    }
                }
            }
        }

        arsort($merged);

        return array_map(fn ($key, $count) => ['name' => $key, 'visitors' => $count], array_keys($merged), array_values($merged));
    }

    /**
     * Convert channel value to label
     */
    private function getChannelLabel($value): string
    {
        if ($value instanceof Channel) return $value->label();

        if (is_numeric($value)) {
            try {
                return Channel::from((int) $value)->label();
            } catch (\Throwable $e) {
                return "Unknown Channel {$value}";
            }
        }
        return (string) $value;
    }

    /**
     * Convert channel label back to enum value
     */
    private function getChannelValue(string $label): ?int
    {
        foreach (Channel::cases() as $case) {
            if ($case->label() !== $label) continue;
            return $case->value;
        }
        return null;
    }

    /**
     * Convert device type to label
     */
    private function getDeviceLabel($value): string
    {
        if ($value instanceof DeviceType) return $value->label();

        if (is_numeric($value)) {
            try {
                return DeviceType::from((int) $value)->label();
            } catch (\Throwable $e) {
                return "Unknown Device {$value}";
            }
        }
        return (string) $value;
    }

    private function getDeviceValue(string $label): ?int
    {
        foreach (DeviceType::cases() as $case) {
            if ($case->label() === $label) return $case->value;
        }
        return null;
    }

    /**
     * Get metrics with applied filters (computed from Session records)
     */

    /**
     * Get country GeoJSON features for a specific set of country codes.
     */
    public function postCountriesGeoJson(Request $request)
    {
        $validated = $request->validate([
            'countries' => ['required', 'array'],
            'countries.*' => ['string', 'size:2'],
        ]);

        $requestedCodes = collect($validated['countries'])
            ->map(fn ($code) => strtoupper(trim($code)))
            ->filter(fn ($code) => $code !== '' && $code !== '-99')
            ->unique()
            ->values();

        if ($requestedCodes->isEmpty()) {
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
            ]);
        }

        $geoJsonPath = public_path('countries.geojson');
        if (!File::exists($geoJsonPath)) {
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
            ], 404);
        }

        $geoJson = json_decode(File::get($geoJsonPath), true);
        if (!is_array($geoJson) || !isset($geoJson['features']) || !is_array($geoJson['features'])) {
            return response()->json([
                'error' => 'Invalid countries GeoJSON source',
            ], 500);
        }

        $requestedCodeLookup = $requestedCodes->flip();

        $filteredFeatures = array_values(array_filter($geoJson['features'], function ($feature) use ($requestedCodeLookup) {
            if (!is_array($feature)) {
                return false;
            }

            $code = $this->countryCodeFromGeoJsonFeature($feature);

            return $code !== '' && $requestedCodeLookup->has($code);
        }));

        return response()->json([
            'type' => 'FeatureCollection',
            'name' => $geoJson['name'] ?? 'countries',
            'features' => $filteredFeatures,
        ]);
    }

    /**
     * Get paginated details for a category
     */
    public function getDetails(Request $request, $category)
    {
        [$query, $startDate, $endDate, $filters] = $this->buildBaseQuery($request);
        $search = $request->query('search', '');
        $cursor = $request->query('cursor');
        $perPage = 20;

        // Apply category-specific logic
        $column = match ($category) {
            'channels' => 'channel',
            'sources' => 'referrer_domain',
            'utm_campaigns' => 'utm_campaign',
            'top_pages', 'pages' => 'entry_page',
            'entry_pages' => 'entry_page',
            'exit_pages' => 'exit_page',
            'countries' => 'country_code',
            'regions' => 'subdivision_code',
            'cities' => 'city',
            'browsers' => 'browser',
            'operating_systems', 'os' => 'os',
            'devices' => 'device_type',
            default => null,
        };

        if (!$column) {
            return response()->json(['error' => 'Invalid category'], 400);
        }

        // Apply search filter
        if ($search && in_array($category, ['pages', 'top_pages', 'entry_pages', 'exit_pages'])) {
            $query->where('entry_page', 'like', "%{$search}%");
        } elseif ($search) {
            $query->where($column, 'like', "%{$search}%");
        }

        // Get aggregated data
        $baseQuery = clone $query;
        $data = $baseQuery
            ->selectRaw("{$column} as name, COUNT(*) as visitors")
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('visitors');

        $total = $data->count();
        
        // Get the sum of all visitors without loading all rows into memory
        $totalVisitors = (clone $query)
            ->whereNotNull($column)
            ->count();
        
        $results = match ($category) {
            'pages', 'top_pages' => $data->limit($perPage)->get(),
            default => $data->limit($perPage)->get(),
        };

        return response()->json([
            'data' => $results,
            'total' => $total,
            'total_visitors' => $totalVisitors,
            'has_more' => $results->count() >= $perPage,
        ]);
    }

    /**
     * Parse filters from request
     */
    private function parseFilters(Request $request): array
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

    /**
     * Build base query with date range and filters
     */
    private function buildBaseQuery(Request $request): array
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applySessionFilters($query, $filters);

        return [$query, $startDate, $endDate, $filters];
    }

    private function countryCodeFromGeoJsonFeature(array $feature): string
    {
        $properties = $feature['properties'] ?? [];
        if (!is_array($properties)) return '';
        $code = strtoupper(trim((string) ($properties['ISO3166-1-Alpha-2'] ?? $properties['ISO_A2'] ?? '')));
        return $code !== '-99' ? $code : '';
    }
}