<?php

namespace App\Services;

use App\Models\DailyStat;
use App\Models\Pageview;
use App\Models\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DailyStatService
{
    private const CACHE_KEY_PREFIX = 'last_aggregated_session_';

    public static function updateCurrentDay(int $siteId): void
    {
        $today = now()->toDateString();
        $cacheKey = self::CACHE_KEY_PREFIX.$siteId;
        $lastAggregatedAt = Cache::get($cacheKey);
        $lastAggregatedAtCarbon = $lastAggregatedAt ? Carbon::parse($lastAggregatedAt) : null;

        self::computeForDate($siteId, $today, $lastAggregatedAtCarbon);

        $latestSessionAt = Session::where('site_id', $siteId)
            ->whereDate('started_at', $today)
            ->max('started_at');

        $latestPageviewAt = null;
        if (config('analytics.track_page_views', true)) {
            $latestPageviewAt = Pageview::where('site_id', $siteId)
                ->whereDate('viewed_at', $today)
                ->max('viewed_at');
        }

        $timestamps = array_filter([$latestSessionAt, $latestPageviewAt]);
        $watermark = empty($timestamps)
            ? now()->toDateTimeString()
            : collect($timestamps)->map(fn ($timestamp) => Carbon::parse($timestamp))->max()->toDateTimeString();

        Cache::put($cacheKey, $watermark, now()->endOfDay()->addDay());
    }

    public static function aggregateForDate(int $siteId, string $date, bool $prune = false): void
    {
        self::computeForDate($siteId, $date);
        if ($prune) self::pruneForDate($siteId, $date);
    }

    public static function pruneForDate(int $siteId, string $date): void
    {
        Session::where('site_id', $siteId)
            ->whereDate('started_at', $date)
            ->delete();
    }

    public static function computeForDate(int $siteId, string $date, ?Carbon $since = null): void
    {
        if ($since !== null) {
            $hasNewSessions = Session::where('site_id', $siteId)
                ->whereDate('started_at', $date)
                ->where('started_at', '>', $since)
                ->exists();

            $hasNewPageviews = config('analytics.track_page_views', true)
                ? Pageview::where('site_id', $siteId)
                    ->whereDate('viewed_at', $date)
                    ->where('viewed_at', '>', $since)
                    ->exists()
                : false;

            if (! $hasNewSessions && ! $hasNewPageviews) return;
        }

        $sessQ = fn () => Session::where('site_id', $siteId)
            ->whereDate('started_at', $date)
            ->when($since, fn ($query) => $query->where('started_at', '>', $since));

        $visits = $sessQ()->count();
        $visitors = $sessQ()->distinct('visitor_id')->count();
        $pageviews = (int) ($sessQ()->sum('pageviews') ?? 0);
        $bounced = $sessQ()->where('is_bounce', true)->count();

        $avgDuration = (int) ($sessQ()->whereNotNull('duration')->avg('duration') ?? 0);
        $bounceRate = $visits > 0 ? round($bounced / $visits * 100, 2) : null;
        $viewsPerVisit = $visits > 0 ? round($pageviews / $visits, 2) : null;

        $agg = function (string $groupCol) use ($sessQ): array {
            return $sessQ()
                ->whereNotNull($groupCol)
                ->groupBy($groupCol)
                ->select(
                    DB::raw("{$groupCol} as grp_key"),
                    DB::raw('COUNT(*) as visits'),
                    DB::raw('COUNT(DISTINCT visitor_id) as visitors'),
                    DB::raw('SUM(pageviews) as pageviews'),
                    DB::raw('ROUND(AVG(CASE WHEN is_bounce THEN 100.0 ELSE 0 END), 1) as bounce_rate'),
                    DB::raw('ROUND(AVG(COALESCE(duration, 0)), 0) as avg_duration'),
                )
                ->orderByDesc('visits')
                ->get()
                ->map(fn ($row) => [
                    'key' => $row->grp_key,
                    'visits' => (int) $row->visits,
                    'visitors' => (int) $row->visitors,
                    'pageviews' => (int) $row->pageviews,
                    'bounce_rate' => (float) $row->bounce_rate,
                    'avg_duration' => (int) $row->avg_duration,
                ])
                ->values()
                ->toArray();
        };

        $browsersAgg = $sessQ()
            ->whereNotNull('browser')
            ->groupBy('browser', 'browser_version')
            ->select('browser', 'browser_version', DB::raw('COUNT(*) as visits'), DB::raw('COUNT(DISTINCT visitor_id) as visitors'))
            ->orderByDesc('visits')
            ->get()
            ->groupBy('browser')
            ->map(fn ($rows, $browser) => [
                'key' => $browser,
                'visits' => (int) $rows->sum('visits'),
                'visitors' => (int) $rows->sum('visitors'),
                'versions' => $rows
                    ->sortByDesc('visits')
                    ->map(fn ($row) => ['key' => $row->browser_version, 'visits' => (int) $row->visits, 'visitors' => (int) $row->visitors])
                    ->values()
                    ->toArray(),
            ])
            ->sortByDesc('visits')
            ->values()
            ->toArray();

        $osAgg = $sessQ()
            ->whereNotNull('os')
            ->groupBy('os', 'os_version')
            ->select('os', 'os_version', DB::raw('COUNT(*) as visits'), DB::raw('COUNT(DISTINCT visitor_id) as visitors'))
            ->orderByDesc('visits')
            ->get()
            ->groupBy('os')
            ->map(fn ($rows, $os) => [
                'key' => $os,
                'visits' => (int) $rows->sum('visits'),
                'visitors' => (int) $rows->sum('visitors'),
                'versions' => $rows
                    ->sortByDesc('visits')
                    ->map(fn ($row) => ['key' => $row->os_version, 'visits' => (int) $row->visits, 'visitors' => (int) $row->visitors])
                    ->values()
                    ->toArray(),
            ])
            ->sortByDesc('visits')
            ->values()
            ->toArray();

        $regionsAgg = $sessQ()
            ->whereNotNull('subdivision_code')
            ->groupBy('subdivision_code', 'country_code')
            ->select('subdivision_code', 'country_code', DB::raw('COUNT(*) as visits'), DB::raw('COUNT(DISTINCT visitor_id) as visitors'))
            ->orderByDesc('visits')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->subdivision_code,
                'country_code' => $row->country_code,
                'visits' => (int) $row->visits,
                'visitors' => (int) $row->visitors,
            ])
            ->toArray();

        $citiesAgg = $sessQ()
            ->whereNotNull('city')
            ->groupBy('city', 'subdivision_code', 'country_code')
            ->select('city', 'subdivision_code', 'country_code', DB::raw('COUNT(*) as visits'), DB::raw('COUNT(DISTINCT visitor_id) as visitors'))
            ->orderByDesc('visits')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->city,
                'subdivision_code' => $row->subdivision_code,
                'country_code' => $row->country_code,
                'visits' => (int) $row->visits,
                'visitors' => (int) $row->visitors,
            ])
            ->toArray();

        $topPagesAgg = null;
        if (config('analytics.track_page_views', true)) {
            $topPagesAgg = Pageview::where('pageviews.site_id', $siteId)
                ->whereDate('viewed_at', $date)
                ->when($since, fn ($query) => $query->where('viewed_at', '>', $since))
                ->groupBy('pathname')
                ->select(
                    'pathname',
                    DB::raw('COUNT(*) as pageviews'),
                    DB::raw('COUNT(DISTINCT session_id) as visits'),
                    DB::raw('COUNT(DISTINCT sessions.visitor_id) as visitors')
                )
                ->join('sessions', 'pageviews.session_id', '=', 'sessions.id')
                ->orderByDesc('pageviews')
                ->get()
                ->map(fn ($row) => [
                    'key' => $row->pathname,
                    'pageviews' => (int) $row->pageviews,
                    'visits' => (int) $row->visits,
                    'visitors' => (int) $row->visitors,
                ])
                ->toArray();
        }

        $entryPagesAgg = $sessQ()
            ->groupBy('entry_page')
            ->select('entry_page', DB::raw('COUNT(*) as visits'), DB::raw('COUNT(DISTINCT visitor_id) as visitors'), DB::raw('ROUND(AVG(CASE WHEN is_bounce THEN 100.0 ELSE 0 END),1) as bounce_rate'))
            ->orderByDesc('visits')
            ->get()
            ->map(fn ($row) => ['key' => $row->entry_page, 'visits' => (int) $row->visits, 'visitors' => (int) $row->visitors, 'bounce_rate' => (float) $row->bounce_rate])
            ->toArray();

        $exitPagesAgg = $sessQ()
            ->groupBy('exit_page')
            ->select('exit_page', DB::raw('COUNT(*) as visits'), DB::raw('COUNT(DISTINCT visitor_id) as visitors'))
            ->orderByDesc('visits')
            ->get()
            ->map(fn ($row) => ['key' => $row->exit_page, 'visits' => (int) $row->visits, 'visitors' => (int) $row->visitors])
            ->toArray();

        $computed = [
            'visitors' => $visitors,
            'visits' => $visits,
            'pageviews' => $pageviews,
            'views_per_visit' => $viewsPerVisit,
            'bounce_rate' => $bounceRate,
            'avg_duration' => $avgDuration,
            'channels_agg' => $agg('channel'),
            'referrers_agg' => $agg('referrer_domain'),
            'utm_sources_agg' => $agg('utm_source'),
            'utm_mediums_agg' => $agg('utm_medium'),
            'utm_campaigns_agg' => $agg('utm_campaign'),
            'utm_contents_agg' => $agg('utm_content'),
            'utm_terms_agg' => $agg('utm_term'),
            'countries_agg' => $agg('country_code'),
            'regions_agg' => $regionsAgg,
            'cities_agg' => $citiesAgg,
            'browsers_agg' => $browsersAgg,
            'os_agg' => $osAgg,
            'devices_agg' => $agg('device_type'),
            'top_pages_agg' => $topPagesAgg,
            'entry_pages_agg' => $entryPagesAgg,
            'exit_pages_agg' => $exitPagesAgg,
        ];

        if ($since === null) {
            DailyStat::updateOrCreate(
                ['site_id' => $siteId, 'date' => $date],
                $computed,
            );

            return;
        }

        $existing = DailyStat::firstOrNew(['site_id' => $siteId, 'date' => $date]);

        if (! $existing->exists) {
            $existing->fill($computed);
            $existing->save();

            return;
        }

        $mergedVisits = (int) $existing->visits + (int) $computed['visits'];
        $mergedPageviews = (int) $existing->pageviews + (int) $computed['pageviews'];

        $existing->fill([
            'visitors' => (int) $existing->visitors + (int) $computed['visitors'],
            'visits' => $mergedVisits,
            'pageviews' => $mergedPageviews,
            'views_per_visit' => $mergedVisits > 0 ? round($mergedPageviews / $mergedVisits, 2) : null,
            'bounce_rate' => self::mergeWeightedValue(
                $existing->bounce_rate,
                $existing->visits,
                $computed['bounce_rate'],
                $computed['visits'],
                2,
            ),
            'avg_duration' => (int) round(self::mergeWeightedValue(
                $existing->avg_duration,
                $existing->visits,
                $computed['avg_duration'],
                $computed['visits'],
                0,
            )),
            'channels_agg' => self::mergeAggWithRates($existing->channels_agg, $computed['channels_agg']),
            'referrers_agg' => self::mergeAggWithRates($existing->referrers_agg, $computed['referrers_agg']),
            'utm_sources_agg' => self::mergeAggWithRates($existing->utm_sources_agg, $computed['utm_sources_agg']),
            'utm_mediums_agg' => self::mergeAggWithRates($existing->utm_mediums_agg, $computed['utm_mediums_agg']),
            'utm_campaigns_agg' => self::mergeAggWithRates($existing->utm_campaigns_agg, $computed['utm_campaigns_agg']),
            'utm_contents_agg' => self::mergeAggWithRates($existing->utm_contents_agg, $computed['utm_contents_agg']),
            'utm_terms_agg' => self::mergeAggWithRates($existing->utm_terms_agg, $computed['utm_terms_agg']),
            'countries_agg' => self::mergeAggSimple($existing->countries_agg, $computed['countries_agg'], ['key'], ['visits', 'visitors', 'pageviews']),
            'regions_agg' => self::mergeAggSimple($existing->regions_agg, $computed['regions_agg'], ['key', 'country_code'], ['visits', 'visitors']),
            'cities_agg' => self::mergeAggSimple($existing->cities_agg, $computed['cities_agg'], ['key', 'subdivision_code', 'country_code'], ['visits', 'visitors']),
            'browsers_agg' => self::mergeVersionedAgg($existing->browsers_agg, $computed['browsers_agg']),
            'os_agg' => self::mergeVersionedAgg($existing->os_agg, $computed['os_agg']),
            'devices_agg' => self::mergeAggWithRates($existing->devices_agg, $computed['devices_agg']),
            'top_pages_agg' => self::mergeAggSimple($existing->top_pages_agg, $computed['top_pages_agg'], ['key'], ['pageviews', 'visits', 'visitors'], 'pageviews'),
            'entry_pages_agg' => self::mergeAggWithRates($existing->entry_pages_agg, $computed['entry_pages_agg']),
            'exit_pages_agg' => self::mergeAggSimple($existing->exit_pages_agg, $computed['exit_pages_agg'], ['key'], ['visits', 'visitors']),
        ]);

        $existing->save();
    }

    private static function mergeWeightedValue(
        float|int|string|null $leftValue,
        int $leftWeight,
        float|int|string|null $rightValue,
        int $rightWeight,
        int $precision,
    ): ?float {
        $leftWeight = max(0, $leftWeight);
        $rightWeight = max(0, $rightWeight);
        $totalWeight = $leftWeight + $rightWeight;

        if ($totalWeight === 0) {
            return null;
        }

        $left = $leftValue === null ? 0.0 : (float) $leftValue;
        $right = $rightValue === null ? 0.0 : (float) $rightValue;

        return round((($left * $leftWeight) + ($right * $rightWeight)) / $totalWeight, $precision);
    }

    private static function mergeAggWithRates(?array $base, ?array $delta): array
    {
        $base = $base ?? [];
        $delta = $delta ?? [];

        $rows = [];

        foreach ($base as $row) {
            $id = self::aggregateKey($row, ['key']);
            $rows[$id] = [
                'key' => $row['key'] ?? null,
                'visits' => (int) ($row['visits'] ?? 0),
                'visitors' => (int) ($row['visitors'] ?? 0),
                'pageviews' => (int) ($row['pageviews'] ?? 0),
                'bounce_rate' => $row['bounce_rate'] ?? null,
                'avg_duration' => isset($row['avg_duration']) ? (int) $row['avg_duration'] : 0,
            ];
        }

        foreach ($delta as $row) {
            $id = self::aggregateKey($row, ['key']);

            if (! isset($rows[$id])) {
                $rows[$id] = [
                    'key' => $row['key'] ?? null,
                    'visits' => 0,
                    'visitors' => 0,
                    'pageviews' => 0,
                    'bounce_rate' => null,
                    'avg_duration' => 0,
                ];
            }

            $existingVisits = (int) ($rows[$id]['visits'] ?? 0);
            $deltaVisits = (int) ($row['visits'] ?? 0);

            $rows[$id]['bounce_rate'] = self::mergeWeightedValue(
                $rows[$id]['bounce_rate'],
                $existingVisits,
                $row['bounce_rate'] ?? null,
                $deltaVisits,
                1,
            );
            $rows[$id]['avg_duration'] = (int) round(self::mergeWeightedValue(
                $rows[$id]['avg_duration'],
                $existingVisits,
                $row['avg_duration'] ?? null,
                $deltaVisits,
                0,
            ) ?? 0);

            $rows[$id]['visits'] = $existingVisits + $deltaVisits;
            $rows[$id]['visitors'] = (int) ($rows[$id]['visitors'] ?? 0) + (int) ($row['visitors'] ?? 0);
            $rows[$id]['pageviews'] = (int) ($rows[$id]['pageviews'] ?? 0) + (int) ($row['pageviews'] ?? 0);
        }

        $values = array_values($rows);

        usort($values, function (array $a, array $b): int {
            return (int) ($b['visits'] ?? 0) <=> (int) ($a['visits'] ?? 0);
        });

        return $values;
    }

    private static function mergeAggSimple(
        ?array $base,
        ?array $delta,
        array $keyFields,
        array $sumFields,
        string $sortBy = 'visits',
    ): array {
        $base = $base ?? [];
        $delta = $delta ?? [];

        $rows = [];

        foreach ($base as $row) {
            $id = self::aggregateKey($row, $keyFields);
            $rows[$id] = $row;

            foreach ($sumFields as $field) {
                $rows[$id][$field] = (int) ($row[$field] ?? 0);
            }
        }

        foreach ($delta as $row) {
            $id = self::aggregateKey($row, $keyFields);

            if (! isset($rows[$id])) {
                $rows[$id] = [];
                foreach ($keyFields as $field) {
                    $rows[$id][$field] = $row[$field] ?? null;
                }
                foreach ($sumFields as $field) {
                    $rows[$id][$field] = 0;
                }
            }

            foreach ($sumFields as $field) {
                $rows[$id][$field] = (int) ($rows[$id][$field] ?? 0) + (int) ($row[$field] ?? 0);
            }
        }

        $values = array_values($rows);

        usort($values, function (array $a, array $b) use ($sortBy): int {
            return (int) ($b[$sortBy] ?? 0) <=> (int) ($a[$sortBy] ?? 0);
        });

        return $values;
    }

    private static function mergeVersionedAgg(?array $base, ?array $delta): array
    {
        $base = $base ?? [];
        $delta = $delta ?? [];

        $rows = [];

        foreach ($base as $row) {
            $rows[$row['key']] = $row;
        }

        foreach ($delta as $row) {
            $key = $row['key'];

            if (! isset($rows[$key])) {
                $rows[$key] = $row;
                continue;
            }

            $rows[$key]['visits'] = (int) ($rows[$key]['visits'] ?? 0) + (int) ($row['visits'] ?? 0);
            $rows[$key]['visitors'] = (int) ($rows[$key]['visitors'] ?? 0) + (int) ($row['visitors'] ?? 0);
            $rows[$key]['versions'] = self::mergeAggSimple(
                $rows[$key]['versions'] ?? [],
                $row['versions'] ?? [],
                ['key'],
                ['visits', 'visitors'],
            );
        }

        $values = array_values($rows);

        usort($values, function (array $a, array $b): int {
            return (int) ($b['visits'] ?? 0) <=> (int) ($a['visits'] ?? 0);
        });

        return $values;
    }

    private static function aggregateKey(array $row, array $keyFields): string
    {
        $parts = array_map(fn ($field) => (string) ($row[$field] ?? ''), $keyFields);

        return implode('|', $parts);
    }
}
