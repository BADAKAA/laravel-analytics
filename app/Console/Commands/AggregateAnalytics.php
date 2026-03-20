<?php

namespace App\Console\Commands;

use App\Models\DailyStat;
use App\Models\Session;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AggregateAnalytics extends Command
{
    protected $signature = 'analytics:aggregate {--date= : Y-m-d, defaults to yesterday} {--prune : Delete sessions/pageviews after aggregation}';

    protected $description = 'Aggregate sessions + pageviews into daily_stats JSON columns';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : today()->subDay()->toDateString();

        Site::each(function (Site $site) use ($date): void {
            $this->aggregateSite($site, $date);
        });

        return self::SUCCESS;
    }

    private function aggregateSite(Site $site, string $date): void
    {
        $sessQ = fn () => Session::where('site_id', $site->id)
            ->whereDate('started_at', $date);

        $visitors = $sessQ()->count();
        $pageviews = $sessQ()->sum('pageviews');
        $bounced = $sessQ()->where('is_bounce', true)->count();

        $avgDuration = (int) $sessQ()->whereNotNull('duration')->avg('duration');
        $bounceRate = $visitors > 0 ? round($bounced / $visitors * 100, 2) : null;
        $vpp = $visitors > 0 ? round($pageviews / $visitors, 2) : null;

        $agg = function (string $groupCol) use ($sessQ): array {
            return $sessQ()
                ->whereNotNull($groupCol)
                ->groupBy($groupCol)
                ->select(
                    DB::raw("{$groupCol} as grp_key"),
                    DB::raw('COUNT(*) as visitors'),
                    DB::raw('SUM(pageviews) as pageviews'),
                    DB::raw('ROUND(AVG(CASE WHEN is_bounce THEN 100.0 ELSE 0 END), 1) as bounce_rate'),
                    DB::raw('ROUND(AVG(COALESCE(duration, 0)), 0) as avg_duration'),
                )
                ->orderByDesc('visitors')
                ->get()
                ->map(fn ($r) => [
                    'key' => $r->grp_key,
                    'visitors' => (int) $r->visitors,
                    'pageviews' => (int) $r->pageviews,
                    'bounce_rate' => (float) $r->bounce_rate,
                    'avg_duration' => (int) $r->avg_duration,
                ])
                ->values()
                ->toArray();
        };

        $browsersAgg = $sessQ()
            ->whereNotNull('browser')
            ->groupBy('browser', 'browser_version')
            ->select('browser', 'browser_version', DB::raw('COUNT(*) as visitors'))
            ->orderByDesc('visitors')
            ->get()
            ->groupBy('browser')
            ->map(fn ($rows, $browser) => [
                'key' => $browser,
                'visitors' => (int) $rows->sum('visitors'),
                'versions' => $rows
                    ->sortByDesc('visitors')
                    ->map(fn ($r) => ['key' => $r->browser_version, 'visitors' => (int) $r->visitors])
                    ->values()
                    ->toArray(),
            ])
            ->sortByDesc('visitors')
            ->values()
            ->toArray();

        $osAgg = $sessQ()
            ->whereNotNull('os')
            ->groupBy('os', 'os_version')
            ->select('os', 'os_version', DB::raw('COUNT(*) as visitors'))
            ->orderByDesc('visitors')
            ->get()
            ->groupBy('os')
            ->map(fn ($rows, $os) => [
                'key' => $os,
                'visitors' => (int) $rows->sum('visitors'),
                'versions' => $rows
                    ->sortByDesc('visitors')
                    ->map(fn ($r) => ['key' => $r->os_version, 'visitors' => (int) $r->visitors])
                    ->values()
                    ->toArray(),
            ])
            ->sortByDesc('visitors')
            ->values()
            ->toArray();

        $regionsAgg = $sessQ()
            ->whereNotNull('subdivision_code')
            ->groupBy('subdivision_code', 'country_code')
            ->select('subdivision_code', 'country_code', DB::raw('COUNT(*) as visitors'))
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($r) => [
                'key' => $r->subdivision_code,
                'country_code' => $r->country_code,
                'visitors' => (int) $r->visitors,
            ])
            ->toArray();

        $citiesAgg = $sessQ()
            ->whereNotNull('city')
            ->groupBy('city', 'subdivision_code', 'country_code')
            ->select('city', 'subdivision_code', 'country_code', DB::raw('COUNT(*) as visitors'))
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($r) => [
                'key' => $r->city,
                'subdivision_code' => $r->subdivision_code,
                'country_code' => $r->country_code,
                'visitors' => (int) $r->visitors,
            ])
            ->toArray();

        $topPagesAgg = $sessQ()
            ->selectRaw('entry_page as pathname, SUM(pageviews) as pageviews, COUNT(DISTINCT visitor_id) as visitors')
            ->groupBy('entry_page')
            ->orderByDesc('pageviews')
            ->get()
            ->map(fn ($r) => ['key' => $r->pathname, 'pageviews' => (int) $r->pageviews, 'visitors' => (int) $r->visitors])
            ->toArray();

        $entryPagesAgg = $sessQ()
            ->groupBy('entry_page')
            ->select('entry_page', DB::raw('COUNT(*) as visitors'), DB::raw('ROUND(AVG(CASE WHEN is_bounce THEN 100.0 ELSE 0 END),1) as bounce_rate'))
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($r) => ['key' => $r->entry_page, 'visitors' => (int) $r->visitors, 'bounce_rate' => (float) $r->bounce_rate])
            ->toArray();

        $exitPagesAgg = $sessQ()
            ->groupBy('exit_page')
            ->select('exit_page', DB::raw('COUNT(*) as visitors'))
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($r) => ['key' => $r->exit_page, 'visitors' => (int) $r->visitors])
            ->toArray();

        DailyStat::updateOrCreate(
            ['site_id' => $site->id, 'date' => $date],
            [
                'visitors' => $visitors,
                'pageviews' => $pageviews,
                'views_per_visit' => $vpp,
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
            ],
        );

        if ($this->option('prune')) {
            Session::where('site_id', $site->id)->whereDate('started_at', $date)->delete();
        }
    }
}
