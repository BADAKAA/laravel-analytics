<?php

namespace App\Models;

use App\Concerns\HasPublicID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Site extends Model
{
    use HasFactory, HasPublicID;

    protected $fillable = [
        'public_id',
        'domain',
        'name',
        'timezone',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(DailyStat::class);
    }

    public function customEvents(): HasMany
    {
        return $this->hasMany(CustomEvent::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'site_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    const CACHE_PREFIX = 'site_id_';
    public static function getID(string $publicID): ?int
    {
        $id = Cache::get(self::CACHE_PREFIX . $publicID);
        if ($id) return $id;
        $id = self::where('public_id', $publicID)->first()?->id;
        if ($id) Cache::put(self::CACHE_PREFIX . $publicID, $id);
        return $id;
    }

    public static function booted()
    {
        static::deleted(function (Site $site) {
            Cache::forget(self::CACHE_PREFIX . $site->public_id);
        });
    }

    public function publicIdField(): string
    {
        return 'public_id';
    }

    public function healUrls(): bool
    {
        return false;
    }
}
