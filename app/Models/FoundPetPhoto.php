<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoundPetPhoto extends Model
{
    protected $fillable = ['path'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(FoundPetReport::class, 'found_pet_report_id');
    }
}