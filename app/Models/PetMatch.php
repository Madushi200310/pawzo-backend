<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetMatch extends Model
{
    protected $fillable = [
        'lost_pet_report_id',
        'found_pet_report_id',
        'score',
        'details',
        'lost_updated_at',
        'found_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'details' => 'array',
        ];
    }

    public function lostReport(): BelongsTo
    {
        return $this->belongsTo(
            LostPetReport::class,
            'lost_pet_report_id'
        );
    }

    public function foundReport(): BelongsTo
    {
        return $this->belongsTo(
            FoundPetReport::class,
            'found_pet_report_id'
        );
    }
}