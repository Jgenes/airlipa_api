<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'fee_rule_id',
        'pricing_version',
        'fee_code',
        'fee_class',
        'transaction_type',
        'payer',
        'beneficiary',
        'calculation_method',
        'flat_amount_minor',
        'rate_bps',
        'min_fee_minor',
        'max_fee_minor',
        'currency',
        'tax_code',
        'tax_rate_bps',
        'refund_policy',
        'recognition_policy',
        'approval_reference',
        'disclosure_en',
        'disclosure_sw',
        'effective_from',
        'effective_to',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'flat_amount_minor' => 'integer',
            'rate_bps' => 'integer',
            'min_fee_minor' => 'integer',
            'max_fee_minor' => 'integer',
            'tax_rate_bps' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }
}
