<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\DeviceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'site_id',
        'visitor_id',
        'started_at',
        'duration',
        'pageviews',
        'is_bounce',
        'entry_page',
        'exit_page',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'referrer',
        'referrer_domain',
        'channel',
        'country_code',
        'subdivision_code',
        'city',
        'browser',
        'browser_version',
        'os',
        'os_version',
        'device_type',
        'screen_width',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'is_bounce' => 'boolean',
            'channel' => Channel::class,
            'device_type' => DeviceType::class,
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function customEvents(): HasMany
    {
        return $this->hasMany(CustomEvent::class);
    }
}
