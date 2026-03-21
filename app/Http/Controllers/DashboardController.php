<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use App\Enums\DeviceType;
use App\Models\DailyStat;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class DashboardController extends Controller
{
    private const DEFAULT_TIMEFRAME = '28_days';
    private const TOP_X_RESULTS = 9;
    private const REALTIME_INTERVAL = 30; // minutes

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
            'realtime' => Carbon::now()->subMinutes(self::REALTIME_INTERVAL),
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

        return inertia('Dashboard', [
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
        $pageviews = $dailyStats->sum('pageviews');
        $avgDuration = $dailyStats->avg('avg_duration');
        $bounceRate = ($visitors > 0) ? round(($dailyStats->sum(function ($stat) { return $stat->bounce_rate * $stat->visitors; }) / $visitors), 2) : 0;
        $viewsPerVisit = ($visitors > 0) ? round($pageviews / $visitors, 2) : 0;

        // Merge aggregated data from all days
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
            'pageviews' => $pageviews,
            'bounce_rate' => $bounceRate,
            'avg_duration' => round($avgDuration, 2),
            'views_per_visit' => $viewsPerVisit,
            'chart_data' => $dailyStats->map(fn ($stat) => [
                'date' => $stat->date->toDateString(),
                'visitors' => $stat->visitors,
                'pageviews' => $stat->pageviews,
                'bounce_rate' => round($stat->bounce_rate, 2),
                'avg_duration' => $stat->avg_duration,
                'views_per_visit' => $stat->visitors > 0 ? round($stat->pageviews / $stat->visitors, 2) : 0,
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
    private function mergeAggregations($aggregations)
    {
        $merged = [];
        foreach ($aggregations as $agg) {
            if (is_array($agg)) {
                foreach ($agg as $key => $count) {
                    // Handle case where $count might be an array or invalid
                    if (is_array($count)) {
                        continue; // Skip nested arrays - they shouldn't be counted
                    }
                    
                    // Ensure count is numeric
                    $numericCount = is_numeric($count) ? (int) $count : 0;
                    $merged[$key] = ($merged[$key] ?? 0) + $numericCount;
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
    public function getMetrics(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $totalSessions = $query->count();
        $bouncedSessions = $query->where('is_bounce', true)->count();
        $totalPageviews = $query->sum('pageviews');
        $avgDuration = $query->avg('duration');

        return response()->json([
            'visitors' => $totalSessions,
            'pageviews' => $totalPageviews,
            'bounce_rate' => $totalSessions > 0 ? round(($bouncedSessions / $totalSessions), 2) : 0,
            'avg_duration' => round($avgDuration ?? 0, 2),
            'views_per_visit' => $totalSessions > 0 ? round($totalPageviews / $totalSessions, 2) : 0,
        ]);
    }

    /**
     * Get visitors chart data with applied filters
     */
    public function getVisitorsChart(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        // Group by date
        $data = $query
            ->selectRaw('DATE(started_at) as date, COUNT(*) as visitors, SUM(pageviews) as pageviews, AVG(duration) as avg_duration, ROUND(SUM(CASE WHEN is_bounce THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as bounce_rate')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($item) => [
                'date' => $item->date,
                'visitors' => $item->visitors,
                'pageviews' => $item->pageviews,
                'bounce_rate' => $item->bounce_rate ?? 0,
                'avg_duration' => $item->avg_duration,
                'views_per_visit' => $item->visitors > 0 ? round($item->pageviews / $item->visitors, 2) : 0,
            ]);

        return response()->json($data);
    }

    /**
     * Get channels data
     */
    public function getChannels(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)->count();

        $data = $query
            ->selectRaw('channel, COUNT(*) as visitors')
            ->groupBy('channel')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get()
            ->map(fn ($item) => [
                'name' => $item->channel ? $this->getChannelLabel($item->channel) : 'Unknown',
                'visitors' => $item->visitors,
            ]);

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get sources data (referrer domains)
     */
    public function getSources(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('referrer_domain')
            ->count();

        $data = $query
            ->selectRaw('referrer_domain, COUNT(*) as visitors')
            ->whereNotNull('referrer_domain')
            ->groupBy('referrer_domain')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get()
            ->map(fn ($item) => [
                'name' => $item->referrer_domain,
                'visitors' => $item->visitors,
            ]);

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get UTM campaigns data
     */
    public function getUtmCampaigns(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('utm_campaign')
            ->count();

        $data = $query
            ->selectRaw('utm_campaign, COUNT(*) as visitors')
            ->whereNotNull('utm_campaign')
            ->groupBy('utm_campaign')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get()
            ->map(fn ($item) => [
                'name' => $item->utm_campaign,
                'visitors' => $item->visitors,
            ]);

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get top pages
     */
    public function getTopPages(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('entry_page')
            ->count();

        // Get pageviews from sessions' entry pages
        $data = $query
            ->selectRaw('entry_page as page, COUNT(*) as visitors')
            ->whereNotNull('entry_page')
            ->groupBy('entry_page')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get();

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get entry pages
     */
    public function getEntryPages(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('entry_page')
            ->count();

        $data = $query
            ->selectRaw('entry_page as page, COUNT(*) as visitors')
            ->whereNotNull('entry_page')
            ->groupBy('entry_page')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get();

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get exit pages
     */
    public function getExitPages(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('exit_page')
            ->count();

        $data = $query
            ->selectRaw('exit_page as page, COUNT(*) as visitors')
            ->whereNotNull('exit_page')
            ->groupBy('exit_page')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get();

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get countries data
     */
    public function getCountries(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('country_code')
            ->count();

        $data = $query
            ->selectRaw('country_code, COUNT(*) as visitors')
            ->whereNotNull('country_code')
            ->groupBy('country_code')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get();

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get regions data
     */
    public function getRegions(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('subdivision_code')
            ->count();

        $data = $query
            ->selectRaw('subdivision_code, COUNT(*) as visitors')
            ->whereNotNull('subdivision_code')
            ->groupBy('subdivision_code')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get()
            ->map(fn ($item) => [
                'subdivision_code' => $item->subdivision_code,
                'visitors' => $item->visitors,
            ]);

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get cities data
     */
    public function getCities(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('city')
            ->count();

        $data = $query
            ->selectRaw('city, COUNT(*) as visitors')
            ->whereNotNull('city')
            ->groupBy('city')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get();

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get browsers data
     */
    public function getBrowsers(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('browser')
            ->count();

        $data = $query
            ->selectRaw('browser, COUNT(*) as visitors')
            ->whereNotNull('browser')
            ->groupBy('browser')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get();

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get operating systems data
     */
    public function getOperatingSystems(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('os')
            ->count();

        $data = $query
            ->selectRaw('os, COUNT(*) as visitors')
            ->whereNotNull('os')
            ->groupBy('os')
            ->orderByDesc('visitors')
            ->limit(self::TOP_X_RESULTS)
            ->get();

        return $this->categoryResponse($request, $data, $total);
    }

    /**
     * Get devices data
     */
    public function getDevices(Request $request)
    {
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

        $total = (clone $query)
            ->whereNotNull('device_type')
            ->count();

        $data = $query
            ->selectRaw('device_type, COUNT(*) as visitors')
            ->whereNotNull('device_type')
            ->groupBy('device_type')
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($item) => [
                'name' => $item->device_type ? $this->getDeviceLabel($item->device_type) : 'Unknown',
                'visitors' => $item->visitors,
            ]);

        return $this->categoryResponse($request, $data, $total);
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
        $siteId = $request->query('site_id');
        $filters = $this->parseFilters($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));
        $search = $request->query('search', '');
        $cursor = $request->query('cursor');
        $perPage = 20;

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = $this->applyFiltersToQuery($query, $filters);

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
        $results = match ($category) {
            'pages', 'top_pages' => $data->limit($perPage)->get(),
            default => $data->limit($perPage)->get(),
        };

        return response()->json([
            'data' => $results,
            'total' => $total,
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
            'referrer_domain' => $request->query('filter_referrer_domain'),
            'utm_campaign' => $request->query('filter_utm_campaign'),
        ];
    }

    private function countryCodeFromGeoJsonFeature(array $feature): string
    {
        $properties = $feature['properties'] ?? [];

        if (!is_array($properties)) {
            return '';
        }

        $code = strtoupper(trim((string) ($properties['ISO3166-1-Alpha-2'] ?? $properties['ISO_A2'] ?? '')));

        return $code !== '-99' ? $code : '';
    }

    /**
     * Apply filters to query
     */
    private function applyFiltersToQuery($query, array $filters)
    {
        if ($filters['channel']) {
            $channelValue = $this->getChannelValue($filters['channel']);
            if ($channelValue !== null) {
                $query->where('channel', $channelValue);
            }
        }
        if ($filters['country']) {
            $query->where('country_code', $filters['country']);
        }
        if ($filters['region']) {
            $query->where('subdivision_code', $filters['region']);
        }
        if ($filters['city']) {
            $query->where('city', $filters['city']);
        }
        if ($filters['device_type']) {
            $deviceValue = $this->getDeviceValue($filters['device_type']);
            if ($deviceValue !== null) {
                $query->where('device_type', $deviceValue);
            }
        }
        if ($filters['browser']) {
            $query->where('browser', $filters['browser']);
        }
        if ($filters['os']) {
            $query->where('os', $filters['os']);
        }
        if ($filters['page']) {
            $query->where('entry_page', $filters['page']);
        }
        if ($filters['referrer_domain']) {
            $query->where('referrer_domain', $filters['referrer_domain']);
        }
        if ($filters['utm_campaign']) {
            $query->where('utm_campaign', $filters['utm_campaign']);
        }

        return $query;
    }
}
