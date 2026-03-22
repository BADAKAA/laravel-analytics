<?php

namespace App\Http\Controllers;

use App\Enums\Timeframe;
use App\Models\Session;
use App\Models\Site;
use App\Services\DashboardAggregationService;
use App\Services\GeoJsonService;
use App\Services\SessionFilters;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    private const REALTIME_INTERVAL_MINUTES = 30;
    private const DEFAULT_TIMEFRAME = '28_days';

    public function __construct(
        private DashboardAggregationService $aggregationService,
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $sites = $user->sites()->get(['sites.id', 'name', 'domain', 'timezone']);

        $siteId = $request->query('site_id') ? (int) $request->query('site_id') : $sites->first()?->id;
        if (!$siteId) return redirect()->route('sites.index');

        $site = Site::findOrFail($siteId);
        Gate::forUser($user)->authorize('view', $site);

        $timeframe = $request->query('timeframe', self::DEFAULT_TIMEFRAME);
        $customStart = $request->query('date_from');
        $customEnd = $request->query('date_to');

        // Get allowed granularities for the timeframe and validate the requested granularity
        $allowedGranularities = Timeframe::getAllowedGranularities($timeframe);
        $requestedGranularity = $request->query('granularity');
        $granularity = (is_string($requestedGranularity) && in_array($requestedGranularity, $allowedGranularities, true))
            ? $requestedGranularity
            : Timeframe::getDefaultGranularity($timeframe);

        $endDate = Carbon::now();
        $startDate = Timeframe::convert($timeframe);
        if (!$startDate) {
            $startDate = $customStart
                ? Carbon::parse($customStart)
                : (Timeframe::convert(self::DEFAULT_TIMEFRAME) ?? Carbon::now()->subDays(28));
        }
        if ($customEnd) $endDate = Carbon::parse($customEnd);

        $unfilteredData = $this->aggregationService->fetchAggregateData(
            $siteId,
            $startDate,
            $endDate,
            [],
            true,
            [],
            $timeframe,
            $granularity
        );

        return inertia('dashboard/Dashboard', [
            'sites' => $sites,
            'selectedSiteId' => $siteId,
            'timeframe' => $timeframe,
            'granularity' => $granularity,
            'timeframeGranularities' => Timeframe::getAllGranularitiesByTimeframe(),
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
        
        if (!$siteId) return response()->json(['count' => 0]);

        // skip permission check for performance reasons, data is not sensitice
        // $site = Site::findOrFail($siteId);
        // Gate::forUser(Auth::user())->authorize('view', $site);

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
     *   "timeframe": "28_days",
     *   "granularity": "day",
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
            'timeframe' => ['nullable', 'string'],
            'granularity' => ['nullable', 'string'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['string'],
            'include_metrics' => ['boolean'],
            'filters' => ['array'],
            'filters.*' => ['nullable', 'string'],
        ]);

        $site = Site::findOrFail($validated['site_id']);
        Gate::forUser(Auth::user())->authorize('view', $site);

        $siteId = $validated['site_id'];
        $startDate = Carbon::parse($validated['date_from']);
        $endDate = Carbon::parse($validated['date_to']);
        $requestedCategories = $validated['categories'];
        $includeMetrics = $validated['include_metrics'] ?? false;
        $filters = $validated['filters'] ?? [];
        $timeframe = $validated['timeframe'] ?? null;
        $granularity = $validated['granularity'] ?? null;

        // Validate granularity against allowed granularities for the timeframe
        if ($timeframe && $granularity) {
            $allowedGranularities = Timeframe::getAllowedGranularities($timeframe);
            if (!in_array($granularity, $allowedGranularities, true)) {
                $granularity = Timeframe::getDefaultGranularity($timeframe);
            }
        }

        $data = $this->aggregationService->fetchAggregateData(
            $siteId,
            $startDate,
            $endDate,
            $requestedCategories,
            $includeMetrics,
            $filters,
            $timeframe,
            $granularity
        );

        return response()->json($data);
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

        $result = GeoJsonService::getCountriesGeoJson($validated['countries']);

        // Handle error responses
        if (isset($result['status'])) {
            return response()->json(['error' => $result['error'] ?? 'Error loading GeoJSON'], $result['status']);
        }

        return response()->json($result);
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
        
        $results = $data->limit($perPage)->get();

        return response()->json([
            'data' => $results,
            'total' => $total,
            'total_visitors' => $totalVisitors,
            'has_more' => $results->count() >= $perPage,
        ]);
    }

    /**
     * Build base query with date range and filters
     */
    private function buildBaseQuery(Request $request): array
    {
        $siteId = $request->query('site_id');
        $site = Site::findOrFail($siteId);
        Gate::forUser(Auth::user())->authorize('view', $site);

        $filters = SessionFilters::parseFromRequest($request);
        $startDate = Carbon::parse($request->query('date_from'));
        $endDate = Carbon::parse($request->query('date_to'));

        $query = Session::where('site_id', $siteId)
            ->whereBetween('started_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

        $query = SessionFilters::apply($query, $filters);

        return [$query, $startDate, $endDate, $filters];
    }
}