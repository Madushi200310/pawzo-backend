<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetReport extends Model
{
    use HasFactory;

    public const REASONS = [
        'fake_profile',
        'inappropriate_content',
        'scam',
        'animal_welfare',
        'other',
    ];

    public const STATUSES = [
        'pending',
        'reviewed',
        'resolved',
        'dismissed',
    ];

    protected $fillable = [
        'pet_id',
        'reporter_id',
        'reason',
        'notes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}