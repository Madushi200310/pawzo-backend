<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAnnouncement extends Model
{
    protected $fillable = [
        'request_id',
        'admin_id',
        'title',
        'body',
        'audience',
        'user_ids',
        'recipient_count',
    ];

    protected function casts(): array
    {
        return [
            'user_ids' => 'array',
            'recipient_count' => 'integer',
        ];
    }
}