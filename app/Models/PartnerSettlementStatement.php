<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerSettlementStatement extends Model
{
    protected $fillable = [
        'statement_reference',
        'provider_code',
        'settlement_account_reference',
        'currency',
        'statement_date',
        'opening_balance_minor',
        'closing_balance_minor',
        'total_inflow_minor',
        'total_outflow_minor',
        'line_count',
        'source',
        'metadata',
        'ingested_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'opening_balance_minor' => 'integer',
            'closing_balance_minor' => 'integer',
            'total_inflow_minor' => 'integer',
            'total_outflow_minor' => 'integer',
            'line_count' => 'integer',
            'metadata' => 'array',
        ];
    }
}
