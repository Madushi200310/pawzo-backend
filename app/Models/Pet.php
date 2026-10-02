<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pet extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'breed',
        'gender',
        'date_of_birth',
        'color',
        'weight',
        'characteristics',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'weight'        => 'decimal:2',
        ];
    }

    // ---------- Relationships ----------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PetPhoto::class);
    }

    public function healthRecords(): HasMany
    {
        return $this->hasMany(HealthRecord::class);
    }

    // ---------- Helpers ----------

    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }
}