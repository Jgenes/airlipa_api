<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderDispatch extends Model
{
    protected $fillable = [
        'reminder_key',
        'reminder_type',
        'subject_type',
        'subject_id',
        'user_id',
        'dispatched_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
