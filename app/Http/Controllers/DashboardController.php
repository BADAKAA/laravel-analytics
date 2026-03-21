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
    private const DEFAULT_TIMEFRAME = '28_days';
    private const TOP_X_RESULTS = 9;
    private const REALTIME_INTERVAL_MINUTES = 30;

    public function index(Request $request)
    {
        $user = Auth::user();
        $sites = $user->sites()->get(['sites.id', 'name', 'domain', 'timezone']);

        $siteId = $request->query('site_id') ? (int) $request->query('site_id') : $sites->first()?->id;

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

        if ($customEnd) {
            $endDate = Carbon::parse($customEnd);
        }

        return inertia('dashboard/Dashboard', [
            'sites' => $sites,
            'selectedSiteId' => $siteId,
            'timeframe' => $timeframe,
            'dateRange' => [
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'unfiltered_data' => $siteId ? $this->getUnfilteredData($siteId, $startDate, $endDate) : null,
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
     * Get unfiltered aggregated data from DailyStat records
     */
    private function getUnfilteredData(int $siteId, Carbon $startDate, Carbon $endDate)
    {
        $dailyStats = DailyStat::where('site_id', $siteId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('date')
            ->get();

        if ($dailyStats->isEmpty()) {
            return [
                'visitors' => 0,
                'visits' => 0,
                'pageviews' => 0,
                'bounce_rate' => 0,
                'avg_duration' => 0,
                'views_per_visit' => 0,
                'chart_data' => [],
                'channels' => [],
                'top_pages' => [],
                'entry_pages' => [],
                'exit_pages' => [],
                'countries' => [],
                'regions' => [],
                'cities' => [],
                'browsers' => [],
                'operating_systems' => [],
                'devices' => [],
            ];
        }

        // Aggregate metrics
        $visitors = $dailyStats->sum('visitors');
        $visits = $dailyStats->sum('visits');
        $pageviews = $dailyStats->sum('pageviews');
        $avgDuration = $dailyStats->avg('avg_duration');
        $bounceRate = ($visits > 0) ? round(($dailyStats->sum(function ($stat) { return $stat->bounce_rate * $stat->visits; }) / $visits), 2) : 0;
        $viewsPerVisit = ($visits > 0) ? round($pageviews / $visits, 2) : 0;

        $channels = $this->mergeAggregations($dailyStats->pluck('channels_agg'));
        $topPages = $this->mergeAggregations($dailyStats->pluck('top_pages_agg'));
        $entryPages = $this->mergeAggregations($dailyStats->pluck('entry_pages_agg'));
        $exitPages = $this->mergeAggregations($dailyStats->pluck('exit_pages_agg'));
        $countries = $this->mergeAggregations($dailyStats->pluck('countries_agg'));
        $regions = $this->mergeAggregations($dailyStats->pluck('regions_agg'));
        $cities = $this->mergeAggregations($dailyStats->pluck('cities_agg'));
        $browsers = $this->mergeAggregations($dailyStats->pluck('browsers_agg'));
        $os = $this->mergeAggregations($dailyStats->pluck('os_agg'));
        $devices = $this->mergeAggregations($dailyStats->pluck('devices_agg'));

        return [
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
            ]),
            'channels' => array_slice($channels, 0, self::TOP_X_RESULTS),
            'top_pages' => array_slice($topPages, 0, self::TOP_X_RESULTS),
            'entry_pages' => array_slice($entryPages, 0, self::TOP_X_RESULTS),
            'exit_pages' => array_slice($exitPages, 0, self::TOP_X_RESULTS),
            'countries' => array_slice($countries, 0, self::TOP_X_RESULTS),
            'regions' => array_slice($regions, 0, self::TOP_X_RESULTS),
            'cities' => array_slice($cities, 0, self::TOP_X_RESULTS),
            'browsers' => array_slice($browsers, 0, self::TOP_X_RESULTS),
            'operating_systems' => array_slice($os, 0, self::TOP_X_RESULTS),
            'devices' => array_slice($devices, 0, self::TOP_X_RESULTS),
        ];
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

        return array_map(fn ($key, $count) => ['name' => $key, 'visits' => $count], array_keys($merged), array_values($merged));
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
    public function getMetrics(Request $request)
    {
        [$query, $startDate, $endDate, $filters] = $this->buildBaseQuery($request);

        $totalVisits = $query->count();
        $totalVisitors = $query->distinct('visitor_id')->count();
        $bouncedSessions = $query->where('is_bounce', true)->count();
        $totalPageviews = $query->sum('pageviews');
        $avgDuration = $query->avg('duration');

        return response()->json([
            'visitors' => $totalVisitors,
            'visits' => $totalVisits,
            'pageviews' => $totalPageviews,
            'bounce_rate' => $totalVisits > 0 ? round(($bouncedSessions / $totalVisits), 2) : 0,
            'avg_duration' => round($avgDuration ?? 0, 2),
            'views_per_visit' => $totalVisits > 0 ? round($totalPageviews / $totalVisits, 2) : 0,
        ]);
    }

    /**
     * Get visitors chart data with applied filters
     */
    public function getVisitorsChart(Request $request)
    {
        [$query, $startDate, $endDate, $filters] = $this->buildBaseQuery($request);

        // Group by date
        $data = $query
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
                'avg_duration' => $item->avg_duration,
                'views_per_visit' => $item->visits > 0 ? round($item->pageviews / $item->visits, 2) : 0,
            ]);

        return response()->json($data);
    }

    /**
     * Get channel, source, or campaign data using generic category method
     */
    public function getChannels(Request $request)
    {
        return $this->getCategory($request, 'channel', 'channel', true);
    }

    public function getSources(Request $request)
    {
        return $this->getCategory($request, 'referrer_domain', 'referrer_domain');
    }

    public function getUtmCampaigns(Request $request)
    {
        return $this->getCategory($request, 'utm_campaign', 'utm_campaign');
    }

    /**
     * Generic method to get category data from sessions
     */
    private function getCategory(Request $request, string $column, string $displayColumn, bool $formatEnum = false): \Illuminate\Http\JsonResponse
    {
        [$query, $startDate, $endDate] = $this->buildBaseQuery($request);

        $total = (clone $query)->whereNotNull($column)->count();

        $data = $query
            ->selectRaw("{$column}, COUNT(*) as visitors")
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get()
            ->map(fn ($item) => [
                'name' => $this->formatCategoryValue($item->$column, $displayColumn, $formatEnum),
                'visitors' => $item->visitors,
            ]);

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Format category values with optional enum conversion
     */
    private function formatCategoryValue($value, string $column, bool $formatEnum = false): string
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
     * Get top pages
     */
    public function getTopPages(Request $request)
    {
        return $this->getPageCategory($request, 'top_pages');
    }

    /**
     * Get entry pages
     */
    public function getEntryPages(Request $request)
    {
        return $this->getPageCategory($request, 'entry_pages');
    }

    /**
     * Get exit pages
     */
    public function getExitPages(Request $request)
    {
        return $this->getPageCategory($request, 'exit_pages');
    }

    /**
     * Generic method to get page data (top, entry, or exit)
     */
    private function getPageCategory(Request $request, string $type): \Illuminate\Http\JsonResponse
    {
        [$query, $startDate, $endDate] = $this->buildBaseQuery($request);
        $filters = $this->parseFilters($request);
        $trackPageViews = config('analytics.track_page_views', true);

        if ($trackPageViews) {
            return $this->getPageCategoryFromPageviews($query, $startDate, $endDate, $filters, $type);
        } else {
            return $this->getPageCategoryFromSessions($query, $filters, $type);
        }
    }

    /**
     * Get page data from pageviews
     */
    private function getPageCategoryFromPageviews($query, Carbon $startDate, Carbon $endDate, array $filters, string $type): \Illuminate\Http\JsonResponse
    {
        $siteId = $query->where('site_id', null)->getBindings()[0] ?? null;
        $query = Pageview::where('pageviews.site_id', $siteId)
            ->whereBetween('viewed_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query->join('sessions', 'pageviews.session_id', '=', 'sessions.id');

        // Add page type filter
        if ($type === 'entry_pages') {
            $query->where('is_entry', true);
        } elseif ($type === 'exit_pages') {
            $query->whereRaw('pageviews.pathname = sessions.exit_page');
        }
        $query = $this->applyFilters($query, $filters, 'pageview');

        $total = (clone $query)->count();
        $data = $query
            ->selectRaw('pageviews.pathname as page, COUNT(*) as visitors')
            ->groupBy('pageviews.pathname')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get()
            ->map(fn ($item) => [
                'name' => $item->page,
                'visitors' => $item->visitors,
            ]);

        return $this->categoryResponse(request(), $data, $total);
    }

    /**
     * Get page data from sessions
     */
    private function getPageCategoryFromSessions($query, array $filters, string $type): \Illuminate\Http\JsonResponse
    {
        $query = $this->applyFilters($query, $filters);

        $column = match ($type) {
            'entry_pages' => 'entry_page',
            'exit_pages' => 'exit_page',
            default => 'entry_page', // top_pages defaults to entry_page
        };

        $total = (clone $query)->whereNotNull($column)->count();
        $data = $query
            ->selectRaw("{$column} as page, COUNT(*) as visitors")
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get();

        return $this->categoryResponse(request(), $data, $total);
    }

    public function getCountries(Request $request)
    {
        return $this->getCategory($request, 'country_code', 'country_code');
    }

    public function getRegions(Request $request)
    {
        return $this->getCategory($request, 'subdivision_code', 'subdivision_code');
    }

    public function getCities(Request $request)
    {
        return $this->getCategory($request, 'city', 'city');
    }

    public function getBrowsers(Request $request)
    {
        return $this->getCategory($request, 'browser', 'browser');
    }

    public function getOperatingSystems(Request $request)
    {
        return $this->getCategory($request, 'os', 'os');
    }

    public function getDevices(Request $request)
    {
        return $this->getCategory($request, 'device_type', 'device_type', true);
    }

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
     * Return category data, optionally including total visitors across all values.
     */
    private function categoryResponse(Request $request, $data, int $total)
    {
        if ($request->boolean('include_total')) {
            return response()->json([
                'data' => $data,
                'total' => $total,
            ]);
        }

        return response()->json($data);
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

        $query = $this->applyFilters($query, $filters);

        return [$query, $startDate, $endDate, $filters];
    }

    /**
     * Apply filters to query - unified method handling both sessions and pageviews
     */
    private function applyFilters($query, array $filters, ?string $tablePrefix = null): mixed
    {
        $prefix = $tablePrefix === 'pageview' ? 'sessions.' : '';

        if ($filters['channel']) {
            $channelValue = $this->getChannelValue($filters['channel']);
            if ($channelValue !== null) {
                $query->where("{$prefix}channel", $channelValue);
            }
        }
        if ($filters['country']) {
            $query->where("{$prefix}country_code", $filters['country']);
        }
        if ($filters['region']) {
            $query->where("{$prefix}subdivision_code", $filters['region']);
        }
        if ($filters['city']) {
            $query->where("{$prefix}city", $filters['city']);
        }
        if ($filters['device_type']) {
            $deviceValue = $this->getDeviceValue($filters['device_type']);
            if ($deviceValue !== null) {
                $query->where("{$prefix}device_type", $deviceValue);
            }
        }
        if ($filters['browser']) {
            $query->where("{$prefix}browser", $filters['browser']);
        }
        if ($filters['os']) {
            $query->where("{$prefix}os", $filters['os']);
        }
        if ($filters['page']) {
            if ($tablePrefix === 'pageview') {
                $query->where('pageviews.pathname', $filters['page']);
            }
        }
        if ($filters['entry_page']) {
            $query->where("{$prefix}entry_page", $filters['entry_page']);
        }
        if ($filters['exit_page']) {
            $query->where("{$prefix}exit_page", $filters['exit_page']);
        }
        if ($filters['referrer_domain']) {
            $query->where("{$prefix}referrer_domain", $filters['referrer_domain']);
        }
        if ($filters['utm_campaign']) {
            $query->where("{$prefix}utm_campaign", $filters['utm_campaign']);
        }

        return $query;
    }

    private function countryCodeFromGeoJsonFeature(array $feature): string
    {
        $properties = $feature['properties'] ?? [];
        if (!is_array($properties)) return '';
        $code = strtoupper(trim((string) ($properties['ISO3166-1-Alpha-2'] ?? $properties['ISO_A2'] ?? '')));
        return $code !== '-99' ? $code : '';
    }
}