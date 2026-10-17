<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'admin_id',
        'method',
        'route',
        'action',
        'targets',
        'response_status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'targets' => 'array',
            'response_status' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}