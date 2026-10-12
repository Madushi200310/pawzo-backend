<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public const TYPE_SMART_MATCH = 'smart_match_found';
    public const TYPE_VACCINATION_REMINDER = 'vaccination_reminder';
    public const TYPE_MEDICINE_REMINDER = 'medicine_reminder';
    public const TYPE_RENTAL_REQUEST = 'rental_request';
    public const TYPE_BUSINESS_APPROVED = 'business_approved';
    public const TYPE_BUSINESS_REJECTED = 'business_rejected';
    public const TYPE_ADMIN_ANNOUNCEMENT = 'admin_announcement';

    public const TYPES = [
        self::TYPE_SMART_MATCH,
        self::TYPE_VACCINATION_REMINDER,
        self::TYPE_MEDICINE_REMINDER,
        self::TYPE_RENTAL_REQUEST,
        self::TYPE_BUSINESS_APPROVED,
        self::TYPE_BUSINESS_REJECTED,
        self::TYPE_ADMIN_ANNOUNCEMENT,
    ];

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'data', 'action_url', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->read_at = now();
            $this->save();
        }
    }

    // Scope for queries: Notification::unread()->get()
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }
}