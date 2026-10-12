<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PetService extends Model
{
    public const SERVICE_TYPES = [
        'veterinary',
        'grooming',
        'boarding',
        'sitting',
        'walking',
        'training',
    ];

    public const STATUSES = ['pending', 'approved', 'rejected', 'removed'];
    public const MAX_PHOTOS = 5;

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'service_type',
        'description',
        'short_description',
        'price_from',
        'price_to',
        'currency',
        'location_name',
        'latitude',
        'longitude',
        'contact_phone',
        'contact_email',
        'opening_hours',
        'status',
        'rejection_reason',
        'approved_at',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'price_from' => 'float',
            'price_to' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'opening_hours' => 'array',
            'approved_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PetServicePhoto::class)->orderBy('id');
    }

    /**
     * Generate a unique slug from the service name.
     */
    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 1;

        while (static::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.(++$counter);
        }

        return $slug;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}