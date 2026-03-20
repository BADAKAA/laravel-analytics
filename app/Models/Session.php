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
        string $timezone,
        array $createData,
        array $updateData
    ): void {
        $today = today($timezone);

        // Calculate duration for update
        $startTime = strtotime($createData['started_at']);
        $updateData['duration'] = max(0, now($timezone)->timestamp - $startTime);

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
        string $timezone,
        array $createData,
        array $updateData
    ): void {
        $today = today($timezone);
        $startTime = strtotime($createData['started_at']);
        $duration = max(0, now($timezone)->timestamp - $startTime);

        // Convert all values to scalar types (handle enums, etc.)
        $insertData = [];
        foreach ($createData as $key => $value) {
            // Convert backed enums to their backing value
            if (is_object($value) && property_exists($value, 'value')) {
                $insertData[$key] = $value->value;
            } elseif (is_bool($value)) {
                $insertData[$key] = (int) $value;
            } else {
                $insertData[$key] = $value;
            }
        }

        // Query 1: INSERT OR IGNORE (SQLite) or INSERT IGNORE (MySQL)
        $insertColumns = implode(',', array_keys($insertData));
        $placeholders = implode(',', array_fill(0, count($insertData), '?'));
        $insertValues = array_values($insertData);
        
        $driver = DB::getDriverName();
        $ignoreClause = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        DB::statement(
            "{$ignoreClause} INTO sessions ({$insertColumns}) VALUES ({$placeholders})",
            $insertValues
        );

        // Query 2: UPDATE matching session from today
        $updateSql = "UPDATE sessions 
                     SET pageviews = pageviews + 1, 
                         exit_page = ?,
                         is_bounce = 0,
                         duration = ?
                     WHERE site_id = ? 
                     AND visitor_id = ? 
                     AND DATE(started_at) = ?";

        DB::statement($updateSql, [
            $updateData['exit_page'] ?? $createData['exit_page'],
            $duration,
            $siteId,
            $visitorId,
            $today->toDateString(),
        ]);
    }

    /**
     * Upsert using INSERT...ON CONFLICT UPDATE (SQLite) or INSERT...ON DUPLICATE KEY UPDATE (MySQL).
     * Single atomic query - the most optimized approach.
     * Supports both SQLite and MySQL with driver-specific syntax.
     * Properly converts Enum objects to their backing values for raw SQL.
     */
    public static function upsertFromPageviewRawSQLAtomic(
        int $siteId,
        string $visitorId,
        string $timezone,
        array $createData,
        array $updateData
    ): void {
        $today = today($timezone);
        $startTime = strtotime($createData['started_at']);
        $duration = max(0, now($timezone)->timestamp - $startTime);

        // Convert all values to scalar types (handle enums, etc.)
        $insertData = [];
        foreach ($createData as $key => $value) {
            // Convert backed enums to their backing value
            if (is_object($value) && property_exists($value, 'value')) {
                $insertData[$key] = $value->value;
            } elseif (is_bool($value)) {
                $insertData[$key] = (int) $value;
            } else {
                $insertData[$key] = $value;
            }
        }

        // Add session_date for unique constraint
        $insertData['session_date'] = $today->toDateString();

        // Build INSERT statement
        $insertColumns = implode(',', array_keys($insertData));
        $placeholders = implode(',', array_fill(0, count($insertData), '?'));
        $insertValues = array_values($insertData);
        
        $driver = DB::getDriverName();
        $exitPage = $updateData['exit_page'] ?? $createData['exit_page'];

        if ($driver === 'sqlite') {
            // SQLite: INSERT...ON CONFLICT(columns) DO UPDATE SET
            $sql = "INSERT INTO sessions ({$insertColumns}) VALUES ({$placeholders})
                    ON CONFLICT(site_id, visitor_id, session_date) DO UPDATE SET 
                        pageviews = pageviews + 1,
                        exit_page = ?,
                        is_bounce = 0,
                        duration = ?";
            
            DB::statement($sql, array_merge($insertValues, [$exitPage, $duration]));
        } else {
            // MySQL: INSERT...ON DUPLICATE KEY UPDATE
            $sql = "INSERT INTO sessions ({$insertColumns}) VALUES ({$placeholders})
                    ON DUPLICATE KEY UPDATE 
                        pageviews = pageviews + 1,
                        exit_page = ?,
                        is_bounce = 0,
                        duration = ?";
            
            DB::statement($sql, array_merge($insertValues, [$exitPage, $duration]));
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
