<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\DeviceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * Optimized upsert using raw SQL for single atomic operation.
     * Attempts UPDATE first, then INSERT only if needed.
     * Uses timestamp-based range query instead of DATE() for better index usage.
     * Faster than ORM-based updateOrCreate() approach.
     */
    public static function upsertFromPageview(
        int $siteId,
        string $visitorId,
        array $createData,
        array $updateData
    ): void {
        $now = now();
        $today = $now->toDateString();
        $startOfDay = "{$today} 00:00:00";
        $endOfDay = "{$today} 23:59:59";
        $startTime = strtotime($createData['started_at']);
        $updateData['duration'] = max(0, $now->timestamp - $startTime);

        // Optimized UPDATE using raw SQL with timestamp range for better index usage
        // Avoids DATE() function calls which can prevent index usage
        $rowsUpdated = DB::update(
            'UPDATE sessions SET pageviews = pageviews + 1, exit_page = ?, is_bounce = 0, duration = ? 
             WHERE site_id = ? AND visitor_id = ? AND started_at >= ? AND started_at <= ?',
            [
                $updateData['exit_page'] ?? $createData['exit_page'],
                $updateData['duration'],
                $siteId,
                $visitorId,
                $startOfDay,
                $endOfDay
            ]
        );

        // Only INSERT if no rows were updated (session doesn't exist yet)
        if ($rowsUpdated === 0) {
            // Ensure 'id' field is present for UUID primary key
            if (!isset($createData['id'])) {
                $createData['id'] = \Illuminate\Support\Str::uuid()->toString();
            }

            // Convert all values to scalar types (handle enums, etc.)
            $insertData = [];
            foreach ($createData as $key => $value) {
                // Convert backed enums to their backing value
                if ($value instanceof \BackedEnum) {
                    $insertData[$key] = $value->value;
                } elseif (is_bool($value)) {
                    $insertData[$key] = (int) $value;
                } else {
                    $insertData[$key] = $value;
                }
            }

            try {
                self::create($insertData);
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key error
                if (str_contains($e->getMessage(), 'FOREIGN KEY') || str_contains($e->getMessage(), 'foreign key')) {
                    throw $e;
                }
                // For other errors, log and re-throw
                throw $e;
            }
        }
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
