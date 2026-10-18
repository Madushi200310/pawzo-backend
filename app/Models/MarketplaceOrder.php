<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrder extends Model
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
        'user_id',
        'seller_id',
        'product_id',
        'pet_sale_listing_id',
        'quantity',
        'unit_price',
        'total_price',
        'currency',
        'message',
        'status',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function petSaleListing(): BelongsTo
    {
        return $this->belongsTo(PetSaleListing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}