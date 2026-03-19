<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pageview extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'session_id',
        'hostname',
        'pathname',
        'viewed_at',
        'is_entry',
        'is_exit',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'is_entry' => 'boolean',
            'is_exit' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
