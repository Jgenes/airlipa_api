<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'fee_record_id',
        'escrow_contract_id',
        'protected_payment_id',
        'fee_quote_id',
        'currency',
        'platform_fee_minor',
        'provider_charge_minor',
        'tax_minor',
        'borne_by',
        'status',
        'swept_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_fee_minor' => 'integer',
            'provider_charge_minor' => 'integer',
            'tax_minor' => 'integer',
            'swept_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EscrowContract::class, 'escrow_contract_id');
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class, 'protected_payment_id');
    }

    public function feeQuote(): BelongsTo
    {
        return $this->belongsTo(FeeQuote::class, 'fee_quote_id');
    }
}
