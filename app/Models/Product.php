<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_category_id',
        'name',
        'slug',
        'description',
        'price',
        'quantity',
        'brand',
        'sku',
        'photos',
        'is_approved',
        'is_active',
        'rejection_reason',
    ];

    protected $casts = [
        'photos'      => 'array',
        'price'       => 'decimal:2',
        'quantity'    => 'integer',
        'is_approved' => 'boolean',
        'is_active'   => 'boolean',
    ];

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    // ==================== SCOPES ====================

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true)->where('is_active', true);
    }

    public function scopePending($query)
    {
        return $query->where('is_approved', false);
    }

    // ==================== HELPERS ====================

    public function isInStock(): bool
    {
        return $this->quantity > 0;
    }

    // ==================== BOOT ====================

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name) . '-' . uniqid();
            }
        });
    }

    public function reviews(): MorphMany
{
    return $this->morphMany(Review::class, 'reviewable');
}

public function approvedReviews(): MorphMany
{
    return $this->morphMany(Review::class, 'reviewable')->where('is_approved', true);
}

public function averageRating(): float
{
    return round($this->approvedReviews()->avg('rating') ?? 0, 2);
}
}