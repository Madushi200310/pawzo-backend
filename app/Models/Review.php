<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'reviewable_type',
        'reviewable_id',
        'rating',
        'comment',
        'is_approved',
        'rejection_reason',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'rating'      => 'integer',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    // ==================== SCOPES ====================

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopePending($query)
    {
        return $query->where('is_approved', false)->whereNull('rejection_reason');
    }

    public function scopeRejected($query)
    {
        return $query->whereNotNull('rejection_reason');
    }

    // ==================== HELPERS ====================

    public function isApproved(): bool
    {
        return $this->is_approved === true;
    }

    /**
     * Shortcut: get the model class short name (e.g. "Product").
     */
    public function getReviewableTypeShortAttribute(): string
    {
        return class_basename($this->reviewable_type);
    }
}