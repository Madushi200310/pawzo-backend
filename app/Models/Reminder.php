<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reminder extends Model
{
    use HasFactory;

    public const TYPES = [
        'vaccination',
        'medication',
        'deworming',
        'vet_appointment',
        'grooming',
    ];

    protected $fillable = [
        'pet_id',
        'user_id',
        'type',
        'title',
        'description',
        'due_date',
        'remind_at',
        'source_type',
        'source_id',
        'is_completed',
        'completed_at',
        'is_dismissed',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date'     => 'date',
            'remind_at'    => 'datetime',
            'completed_at' => 'datetime',
            'notified_at'  => 'datetime',
            'is_completed' => 'boolean',
            'is_dismissed' => 'boolean',
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

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function isOverdue(): bool
    {
        return ! $this->is_completed
            && ! $this->is_dismissed
            && $this->due_date->isPast();
    }

    public function daysUntilDue(): int
    {
        return now()->startOfDay()->diffInDays($this->due_date, false);
    }
}