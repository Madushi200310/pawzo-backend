<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Complaint extends Model
{
    public const TARGETS = [
        'user' => User::class,
        'business' => Business::class,
        'product' => Product::class,
        'pet-sale' => PetSaleListing::class,
        'pet' => Pet::class,
        'lost' => LostPetReport::class,
        'found' => FoundPetReport::class,
        'stray' => StrayReport::class,
        'service' => PetService::class,
        'rental' => PetRental::class,
        'review' => Review::class,
        'match' => PetMatch::class,
    ];

    public const REASONS = [
        'fake_account',
        'fake_business',
        'scam',
        'fake_report',
        'inappropriate_content',
        'suspicious_activity',
        'service_complaint',
        'incorrect_match',
        'animal_welfare',
        'other',
    ];

    public const STATUSES = [
        'pending',
        'investigating',
        'resolved',
        'dismissed',
    ];

    protected $fillable = [
        'reporter_id',
        'target_type',
        'target_id',
        'reason',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
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