<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PetRental extends Model
{
    public const TYPES = ['dog', 'cat', 'bird', 'rabbit', 'other'];
    public const GENDERS = ['male', 'female', 'unknown'];
    public const STATUSES = ['active', 'paused', 'closed'];
    public const MAX_PHOTOS = 5;

    protected $fillable = [
        'pet_name',
        'pet_type',
        'breed',
        'gender',
        'age',
        'color',
        'age_description',
        'characteristics',
        'purpose',
        'contact_phone',
        'price_per_day',
        'location_name',
        'latitude',
        'longitude',
        'description',
        'available_from',
        'available_to',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price_per_day' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'available_from' => 'date',
            'available_to' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PetRentalPhoto::class)->orderBy('id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PetRentalRequest::class)->orderBy('id');
    }
}
