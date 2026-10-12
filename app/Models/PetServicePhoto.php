<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetServicePhoto extends Model
{
    protected $fillable = ['path'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(PetService::class, 'pet_service_id');
    }
}