<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'session_id',
        'name',
        'props',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'props' => 'array',
            'occurred_at' => 'datetime',
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
