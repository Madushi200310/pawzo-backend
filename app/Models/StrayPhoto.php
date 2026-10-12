<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrayPhoto extends Model
{
    protected $fillable = ['path'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(StrayReport::class, 'stray_report_id');
    }
}