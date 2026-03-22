<?php

namespace App\Services;

use App\Models\DailyStat;
use App\Models\Pageview;
use App\Models\Session;
use Illuminate\Database\Eloquent\Builder;
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

        $sessionQuery = self::sessionQuery($siteId, $date, $since);

        $summary = (clone $sessionQuery)
            ->selectRaw('COUNT(*) as visits')
            ->selectRaw('COUNT(DISTINCT visitor_id) as visitors')
            ->selectRaw('COALESCE(SUM(pageviews), 0) as pageviews')
            ->selectRaw('SUM(CASE WHEN is_bounce THEN 1 ELSE 0 END) as bounced')
            ->selectRaw('COALESCE(AVG(duration), 0) as avg_duration')
            ->first();

        $visits = (int) ($summary->visits ?? 0);
        $visitors = (int) ($summary->visitors ?? 0);
        $pageviews = (int) ($summary->pageviews ?? 0);
        $bounced = (int) ($summary->bounced ?? 0);
        $avgDuration = (int) round((float) ($summary->avg_duration ?? 0));
        $bounceRate = $visits > 0 ? round($bounced / $visits * 100, 2) : null;
        $viewsPerVisit = $visits > 0 ? round($pageviews / $visits, 2) : null;

        $agg = fn (string $groupCol): array => self::aggregateByColumn($sessionQuery, $groupCol);
        $singleColumnAggFields = [
            'channels_agg' => 'channel',
            'referrers_agg' => 'referrer_domain',
            'utm_sources_agg' => 'utm_source',
            'utm_mediums_agg' => 'utm_medium',
            'utm_campaigns_agg' => 'utm_campaign',
            'utm_contents_agg' => 'utm_content',
            'utm_terms_agg' => 'utm_term',
            'countries_agg' => 'country_code',
            'devices_agg' => 'device_type',
        ];

        $singleColumnAggData = [];
        foreach ($singleColumnAggFields as $field => $column) {
            $singleColumnAggData[$field] = $agg($column);
        }

        $browsersAgg = $agg('browser');
        $osAgg = $agg('os');

        $regionsAgg = (clone $sessionQuery)
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

        $citiesAgg = (clone $sessionQuery)
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
                ->where('sessions.site_id', $siteId)
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

        $entryPagesAgg = (clone $sessionQuery)
            ->groupBy('entry_page')
            ->select('entry_page', DB::raw('COUNT(*) as visits'), DB::raw('COUNT(DISTINCT visitor_id) as visitors'), DB::raw('ROUND(AVG(CASE WHEN is_bounce THEN 100.0 ELSE 0 END),1) as bounce_rate'))
            ->orderByDesc('visits')
            ->get()
            ->map(fn ($row) => ['key' => $row->entry_page, 'visits' => (int) $row->visits, 'visitors' => (int) $row->visitors, 'bounce_rate' => (float) $row->bounce_rate])
            ->toArray();

        $exitPagesAgg = (clone $sessionQuery)
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
            'bounce_count' => $bounced,
            'avg_duration' => $avgDuration,
            ...$singleColumnAggData,
            'regions_agg' => $regionsAgg,
            'cities_agg' => $citiesAgg,
            'browsers_agg' => $browsersAgg,
            'os_agg' => $osAgg,
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
        $mergedBounceCount = (int) $existing->bounce_count + (int) $computed['bounce_count'];

        $rateAggFields = [
            'channels_agg',
            'referrers_agg',
            'utm_sources_agg',
            'utm_mediums_agg',
            'utm_campaigns_agg',
            'utm_contents_agg',
            'utm_terms_agg',
            'devices_agg',
            'entry_pages_agg',
        ];

        $simpleAggConfigs = [
            'countries_agg' => [['key'], ['visits', 'visitors', 'pageviews'], 'visits'],
            'regions_agg' => [['key', 'country_code'], ['visits', 'visitors'], 'visits'],
            'cities_agg' => [['key', 'subdivision_code', 'country_code'], ['visits', 'visitors'], 'visits'],
            'browsers_agg' => [['key'], ['visits', 'visitors', 'pageviews'], 'visits'],
            'os_agg' => [['key'], ['visits', 'visitors', 'pageviews'], 'visits'],
            'top_pages_agg' => [['key'], ['pageviews', 'visits', 'visitors'], 'pageviews'],
            'exit_pages_agg' => [['key'], ['visits', 'visitors'], 'visits'],
        ];

        $mergedAggData = [];

        foreach ($rateAggFields as $field) {
            $mergedAggData[$field] = self::mergeAggWithRates($existing->{$field}, $computed[$field] ?? null);
        }

        foreach ($simpleAggConfigs as $field => [$keyFields, $sumFields, $sortBy]) {
            $mergedAggData[$field] = self::mergeAggSimple(
                $existing->{$field},
                $computed[$field] ?? null,
                $keyFields,
                $sumFields,
                $sortBy,
            );
        }

        $existing->fill([
            'visitors' => (int) $existing->visitors + (int) $computed['visitors'],
            'visits' => $mergedVisits,
            'pageviews' => $mergedPageviews,
            'views_per_visit' => $mergedVisits > 0 ? round($mergedPageviews / $mergedVisits, 2) : null,
            'bounce_count' => $mergedBounceCount,
            'avg_duration' => (int) round(self::mergeWeightedValue(
                $existing->avg_duration,
                $existing->visits,
                $computed['avg_duration'],
                $computed['visits'],
                0,
            )),
            'browsers_agg' => $mergedAggData['browsers_agg'],
            'os_agg' => $mergedAggData['os_agg'],

            'channels_agg' => $mergedAggData['channels_agg'],
            'referrers_agg' => $mergedAggData['referrers_agg'],
            'utm_sources_agg' => $mergedAggData['utm_sources_agg'],
            'utm_mediums_agg' => $mergedAggData['utm_mediums_agg'],
            'utm_campaigns_agg' => $mergedAggData['utm_campaigns_agg'],
            'utm_contents_agg' => $mergedAggData['utm_contents_agg'],
            'utm_terms_agg' => $mergedAggData['utm_terms_agg'],
            'countries_agg' => $mergedAggData['countries_agg'],
            'regions_agg' => $mergedAggData['regions_agg'],
            'cities_agg' => $mergedAggData['cities_agg'],
            'devices_agg' => $mergedAggData['devices_agg'],
            'top_pages_agg' => $mergedAggData['top_pages_agg'],
            'entry_pages_agg' => $mergedAggData['entry_pages_agg'],
            'exit_pages_agg' => $mergedAggData['exit_pages_agg'],
        ]);

        $existing->save();
    }

    private static function sessionQuery(int $siteId, string $date, ?Carbon $since = null): Builder
    {
        return Session::query()
            ->where('site_id', $siteId)
            ->whereDate('started_at', $date)
            ->when($since, fn (Builder $query) => $query->where('started_at', '>', $since));
    }

    private static function aggregateByColumn(Builder $sessionQuery, string $groupCol): array
    {
        return (clone $sessionQuery)
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

            $rows[$id] = [];
            foreach ($keyFields as $field) {
                $rows[$id][$field] = $row[$field] ?? null;
            }

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

    private static function aggregateKey(array $row, array $keyFields): string
    {
        $parts = array_map(fn ($field) => (string) ($row[$field] ?? ''), $keyFields);

        return implode('|', $parts);
    }
}
