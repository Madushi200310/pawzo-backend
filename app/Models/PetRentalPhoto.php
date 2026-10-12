<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetRentalPhoto extends Model
{
    protected $fillable = ['pet_rental_id', 'path'];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(PetRental::class, 'pet_rental_id');
    }
}
