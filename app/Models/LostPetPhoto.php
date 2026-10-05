<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LostPetPhoto extends Model
{
    protected $fillable = ['path'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(LostPetReport::class, 'lost_pet_report_id');
    }
}