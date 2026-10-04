<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LostPetReport extends Model
{
    public const TYPES = ['dog', 'cat', 'bird', 'rabbit', 'other'];
    public const GENDERS = ['male', 'female', 'unknown'];
    public const STATUSES = ['open', 'reunited', 'closed'];
    public const MAX_PHOTOS = 5;

    protected $fillable = [
        'pet_name', 'pet_type', 'breed', 'gender', 'color',
        'age_description', 'distinguishing_features', 'description',
        'lost_at', 'location_name', 'latitude', 'longitude',
        'contact_name', 'contact_phone', 'whatsapp_number',
    ];

    protected function casts(): array
    {
        return [
            'lost_at' => 'datetime',
            'resolved_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(LostPetPhoto::class)->orderBy('id');
    }
}