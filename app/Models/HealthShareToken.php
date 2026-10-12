<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthShareToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'pet_id',
        'user_id',
        'token',
        'include',
        'expires_at',
        'view_count',
        'last_viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'include'        => 'array',
            'expires_at'     => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}