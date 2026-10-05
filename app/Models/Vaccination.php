<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vaccination extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'pet_id',
        'vaccine_name',
        'given_date',
        'next_due_date',
        'vet_name',
        'batch_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'given_date'    => 'date',
            'next_due_date' => 'date',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    /**
     * Days until next_due_date (negative if overdue).
     */
    public function daysUntilDue(): ?int
    {
        return $this->next_due_date?->diffInDays(now(), false);
    }

    /**
     * Is this vaccination overdue for its next dose?
     */
    public function isOverdue(): bool
    {
        return $this->next_due_date !== null && $this->next_due_date->isPast();
    }
}