<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDailySnapshot extends Model
{
    protected $fillable = [
        'as_of_date',
        'contracts_created',
        'contracts_accepted',
        'deposits_confirmed',
        'gross_escrow_volume_minor',
        'released_volume_minor',
        'refunded_volume_minor',
        'disputes_opened',
        'disputes_resolved',
        'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'contracts_created' => 'integer',
            'contracts_accepted' => 'integer',
            'deposits_confirmed' => 'integer',
            'gross_escrow_volume_minor' => 'integer',
            'released_volume_minor' => 'integer',
            'refunded_volume_minor' => 'integer',
            'disputes_opened' => 'integer',
            'disputes_resolved' => 'integer',
            'breakdown' => 'array',
        ];
    }
}
