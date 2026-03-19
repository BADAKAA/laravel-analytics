<?php

namespace App\Models;

use App\Enums\SiteRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'user_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'role' => SiteRole::class,
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
