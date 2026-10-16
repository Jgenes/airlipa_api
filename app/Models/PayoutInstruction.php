<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutInstruction extends Model
{
    protected $fillable = [
        'payout_instruction_id',
        'settlement_batch_id',
        'protected_payment_id',
        'merchant_id',
        'payout_account_id',
        'provider_code',
        'currency',
        'gross_released_minor',
        'merchant_fee_minor',
        'merchant_tax_minor',
        'net_payout_minor',
        'idempotency_key',
        'provider_payout_reference',
        'status',
        'ledger_journal_id',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_released_minor' => 'integer',
            'merchant_fee_minor' => 'integer',
            'merchant_tax_minor' => 'integer',
            'net_payout_minor' => 'integer',
            'executed_at' => 'datetime',
        ];
    }

    public function settlementBatch(): BelongsTo
    {
        return $this->belongsTo(SettlementBatch::class, 'settlement_batch_id');
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class, 'protected_payment_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantPayoutAccount::class, 'payout_account_id');
    }

    public function ledgerJournal(): BelongsTo
    {
        return $this->belongsTo(LedgerJournal::class, 'ledger_journal_id');
    }
}
