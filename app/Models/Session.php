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
     * Alternative: Upsert using updateOrCreate() - for benchmarking comparison.
     * This requires 2 queries: SELECT first, then INSERT or UPDATE.
     */
    public static function upsertFromPageview(
        int $siteId,
        string $visitorId,
        array $createData,
        array $updateData
    ): void {
        $today = today();

        // Calculate duration for update
        $startTime = strtotime($createData['started_at']);
        $updateData['duration'] = max(0, now()->timestamp - $startTime);

        // Query 1: SELECT * FROM sessions WHERE site_id=? AND visitor_id=? AND DATE(started_at)=?
        // Query 2: INSERT or UPDATE
        self::where('site_id', $siteId)
            ->where('visitor_id', $visitorId)
            ->whereDate('started_at', $today)
            ->latest('started_at')
            ->limit(1)
            ->update($updateData);

        // Only insert if no update occurred
        if (self::where('site_id', $siteId)
            ->where('visitor_id', $visitorId)
            ->whereDate('started_at', $today)
            ->doesntExist()) {
            self::create($createData);
        }
    }

    /**
     * Upsert using raw SQL with separate INSERT OR IGNORE + UPDATE queries.
     * Two queries but with raw SQL overhead, database-specific syntax.
     * For SQLite: INSERT OR IGNORE, For MySQL: INSERT IGNORE
     */
    public static function upsertFromPageviewRawSQL(
        int $siteId,
        string $visitorId,
        array $createData,
        array $updateData
    ): void {
        $today = today();
        $startTime = strtotime($createData['started_at']);
        $updateData['duration'] = max(0, now()->timestamp - $startTime);

        // Query 1: Try to UPDATE existing session from today
        // This will succeed if a session already exists, fail if it doesn't
        $rowsUpdated = DB::table('sessions')
            ->where('site_id', $siteId)
            ->where('visitor_id', $visitorId)
            ->whereDate('started_at', $today)
            ->update([
                'pageviews' => DB::raw('pageviews + 1'),
                'exit_page' => $updateData['exit_page'] ?? $createData['exit_page'],
                'is_bounce' => 0,
                'duration' => $updateData['duration'],
            ]);

        // Query 2: Only insert if no rows were updated (session doesn't exist yet)
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
