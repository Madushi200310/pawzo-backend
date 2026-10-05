<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PetSaleListing extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pet_type',
        'breed',
        'age',
        'gender',
        'color',
        'description',
        'location',
        'city',
        'district',
        'price',
        'is_negotiable',
        'photos',
        'certificates',
        'contact_phone',
        'contact_whatsapp',
        'status',
        'is_approved',
        'is_active',
        'rejection_reason',
    ];

    protected $casts = [
        'photos'        => 'array',
        'certificates'  => 'array',
        'price'         => 'decimal:2',
        'is_negotiable' => 'boolean',
        'is_approved'   => 'boolean',
        'is_active'     => 'boolean',
    ];

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== SCOPES ====================

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true)->where('is_active', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopePending($query)
    {
        return $query->where('is_approved', false);
    }

    // ==================== HELPERS ====================

    public function isSold(): bool
    {
        return $this->status === 'sold';
    }
}