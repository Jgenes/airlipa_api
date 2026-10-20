<?php

namespace App\Models;

use App\Enums\RefundRequestStatus;
use App\Enums\RefundScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundRequest extends Model
{
    protected $fillable = [
        'refund_id',
        'refund_reference',
        'protected_payment_id',
        'escrow_contract_id',
        'dispute_case_id',
        'milestone_id',
        'requested_by_user_id',
        'currency',
        'scope',
        'principal_minor',
        'fee_refund_minor',
        'tax_refund_minor',
        'total_refund_minor',
        'destination_method',
        'status',
        'provider_code',
        'provider_refund_reference',
        'ledger_journal_id',
        'idempotency_key',
        'reason',
        'failure_reason',
        'metadata',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => RefundScope::class,
            'status' => RefundRequestStatus::class,
            'principal_minor' => 'integer',
            'fee_refund_minor' => 'integer',
            'tax_refund_minor' => 'integer',
            'total_refund_minor' => 'integer',
            'metadata' => 'array',
            'refunded_at' => 'datetime',
        ];
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class);
    }

    public function escrowContract(): BelongsTo
    {
        return $this->belongsTo(EscrowContract::class);
    }

    public function disputeCase(): BelongsTo
    {
        return $this->belongsTo(DisputeCase::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
