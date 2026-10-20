<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BalanceSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_type',
        'subject_type',
        'subject_id',
        'as_of_date',
        'currency',
        'balance_minor',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'balance_minor' => 'integer',
            'as_of_date' => 'date',
            'metadata' => 'array',
        ];
    }
}
