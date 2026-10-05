<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HealthRecord extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPES = [
        'medical_history',
        'vet_visit',
        'treatment',
        'disease',
        'medication',
    ];

    protected $fillable = [
        'pet_id',
        'record_type',
        'title',
        'description',
        'date',
        'vet_name',
        'clinic_name',
        'medication_name',
        'dosage',
        'start_date',
        'end_date',
        'is_ongoing',
    ];

    protected function casts(): array
    {
        return [
            'date'       => 'date',
            'start_date' => 'date',
            'end_date'   => 'date',
            'is_ongoing' => 'boolean',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HealthDocument::class);
    }
}