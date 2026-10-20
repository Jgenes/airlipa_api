<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutFreezeState extends Model
{
    protected $fillable = [
        'scope',
        'is_active',
        'reason',
        'evidence',
        'activated_by_user_id',
        'activated_at',
        'released_by_user_id',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'evidence' => 'array',
            'activated_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
