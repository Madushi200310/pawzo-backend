<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetRentalRequest extends Model
{
    public const STATUSES = ['pending', 'accepted', 'rejected', 'cancelled'];

    protected $fillable = [
        'pet_rental_id',
        'requester_id',
        'start_date',
        'end_date',
        'message',
        'purpose',
        'owner_response',
        'responded_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'responded_at' => 'datetime',
        ];
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(PetRental::class, 'pet_rental_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function user(): BelongsTo
    {
        return $this->requester();
    }
}
