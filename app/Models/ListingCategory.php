<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingCategory extends Model
{
    public const GROUPS = ['pet', 'service', 'rental'];

    protected $fillable = [
        'group',
        'value',
        'name',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}