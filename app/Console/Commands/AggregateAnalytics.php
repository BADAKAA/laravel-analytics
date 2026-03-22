<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\DailyStatService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AggregateAnalytics extends Command
{
    protected $signature = 'analytics:aggregate {--date= : Y-m-d, defaults to yesterday} {--prune : Delete sessions/pageviews after aggregation}';

    protected $description = 'Aggregate sessions + pageviews into daily_stats JSON columns';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : today()->subDay()->toDateString();
        $prune = (bool) $this->option('prune');

        Site::each(function (Site $site) use ($date, $prune): void {
            DailyStatService::aggregateForDate($site->id, $date, $prune);
        });

        return self::SUCCESS;
    }
}
