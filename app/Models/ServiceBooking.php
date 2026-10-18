<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceBooking extends Model
{
    public const STATUSES = [
        'pending',
        'accepted',
        'rejected',
        'completed',
        'cancelled',
    ];

    protected $fillable = [
        'reference',
        'pet_service_id',
        'user_id',
        'scheduled_at',
        'message',
        'provider_response',
        'status',
        'responded_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'responded_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(PetService::class, 'pet_service_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}