<?php

namespace App\Http\Controllers;

use App\Enums\Timeframe;
use App\Models\Session;
use App\Services\DashboardAggregationService;
use App\Services\GeoJsonService;
use App\Services\SessionFilterService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    private const REALTIME_INTERVAL_MINUTES = 30;
    private const DEFAULT_TIMEFRAME = '28_days';

    public function __construct(
        private DashboardAggregationService $aggregationService,
        private SessionFilterService $filterService
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $sites = $user->sites()->get(['sites.id', 'name', 'domain', 'timezone']);

        $siteId = $request->query('site_id') ? (int) $request->query('site_id') : $sites->first()?->id;
        if (!$siteId) return redirect()->route('sites.index');

        $timeframe = $request->query('timeframe', self::DEFAULT_TIMEFRAME);
        $customStart = $request->query('date_from');
        $customEnd = $request->query('date_to');

        $endDate = Carbon::now();
        $startDate = Timeframe::convert($timeframe);
        if (!$startDate) {
            $startDate = $customStart ? Carbon::parse($customStart) : Carbon::now()->subDays(self::DEFAULT_TIMEFRAME);
        }
        if ($customEnd) $endDate = Carbon::parse($customEnd);

        $unfilteredData = $this->aggregationService->fetchAggregateData(
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
            'categories' => ['sometimes', 'array'],
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

        $data = $this->aggregationService->fetchAggregateData(
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
     * Parse filters from request (delegates to filter service)
     */
    private function parseFilters(Request $request): array
    {
        return $this->filterService->parseFromRequest($request);
    }

    /**
     * Apply session filters to a query builder instance (delegates to filter service)
     */
    private function applySessionFilters($query, array $filters, ?string $tablePrefix = null): mixed
    {
        return $this->filterService->applyFilters($query, $filters, $tablePrefix);
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
}