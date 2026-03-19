<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'date',
        'visitors',
        'visits',
        'pageviews',
        'views_per_visit',
        'bounce_rate',
        'avg_duration',
        'channels_agg',
        'referrers_agg',
        'utm_sources_agg',
        'utm_mediums_agg',
        'utm_campaigns_agg',
        'utm_contents_agg',
        'utm_terms_agg',
        'top_pages_agg',
        'entry_pages_agg',
        'exit_pages_agg',
        'countries_agg',
        'regions_agg',
        'cities_agg',
        'browsers_agg',
        'os_agg',
        'devices_agg',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'views_per_visit' => 'decimal:2',
            'bounce_rate' => 'decimal:2',
            'channels_agg' => 'array',
            'referrers_agg' => 'array',
            'utm_sources_agg' => 'array',
            'utm_mediums_agg' => 'array',
            'utm_campaigns_agg' => 'array',
            'utm_contents_agg' => 'array',
            'utm_terms_agg' => 'array',
            'top_pages_agg' => 'array',
            'entry_pages_agg' => 'array',
            'exit_pages_agg' => 'array',
            'countries_agg' => 'array',
            'regions_agg' => 'array',
            'cities_agg' => 'array',
            'browsers_agg' => 'array',
            'os_agg' => 'array',
            'devices_agg' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
