<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\DisputeStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\ProtectedPaymentStatus;
use App\Enums\SettlementStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProtectedPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'protected_payment_id',
        'reference',
        'correlation_id',
        'fee_quote_id',
        'payment_link_id',
        'escrow_contract_id',
        'merchant_id',
        'buyer_id',
        'transaction_type',
        'title',
        'item_description',
        'delivery_method',
        'release_conditions',
        'return_policy',
        'terms_version',
        'terms_hash',
        'currency',
        'principal_amount_minor',
        'buyer_fee_minor',
        'buyer_tax_minor',
        'merchant_fee_minor',
        'merchant_tax_minor',
        'total_payable_minor',
        'merchant_net_minor',
        'funded_amount_minor',
        'protected_balance_minor',
        'reserved_release_minor',
        'reserved_refund_minor',
        'released_amount_minor',
        'refunded_amount_minor',
        'reversed_amount_minor',
        'disputed_amount_minor',
        'status',
        'fulfillment_status',
        'dispute_status',
        'settlement_status',
        'handover_otp_hash',
        'custody_provider_code',
        'custody_provider_reference',
        'state_version',
        'delivery_deadline_at',
        'auto_release_at',
        'funded_at',
        'fulfilled_at',
        'released_at',
        'refunded_at',
        'cancelled_at',
    ];

    protected $hidden = [
        'handover_otp_hash',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'delivery_method' => DeliveryMethod::class,
            'status' => ProtectedPaymentStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'dispute_status' => DisputeStatus::class,
            'settlement_status' => SettlementStatus::class,
            'release_conditions' => 'array',
            'principal_amount_minor' => 'integer',
            'buyer_fee_minor' => 'integer',
            'buyer_tax_minor' => 'integer',
            'merchant_fee_minor' => 'integer',
            'merchant_tax_minor' => 'integer',
            'total_payable_minor' => 'integer',
            'merchant_net_minor' => 'integer',
            'funded_amount_minor' => 'integer',
            'protected_balance_minor' => 'integer',
            'reserved_release_minor' => 'integer',
            'reserved_refund_minor' => 'integer',
            'released_amount_minor' => 'integer',
            'refunded_amount_minor' => 'integer',
            'reversed_amount_minor' => 'integer',
            'disputed_amount_minor' => 'integer',
            'state_version' => 'integer',
            'delivery_deadline_at' => 'datetime',
            'auto_release_at' => 'datetime',
            'funded_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'released_at' => 'datetime',
            'refunded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function feeQuote(): BelongsTo
    {
        return $this->belongsTo(FeeQuote::class, 'fee_quote_id');
    }

    public function paymentLink(): BelongsTo
    {
        return $this->belongsTo(ProtectedPaymentLink::class, 'payment_link_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProtectedPaymentMilestone::class, 'protected_payment_id')->orderBy('sequence_number');
    }

    public function paymentIntents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class, 'protected_payment_id');
    }

    public function latestPaymentIntent(): HasOne
    {
        return $this->hasOne(PaymentIntent::class, 'protected_payment_id')->latestOfMany();
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(FulfillmentEvidence::class, 'protected_payment_id');
    }

    public function disputeCases(): HasMany
    {
        return $this->hasMany(DisputeCase::class, 'protected_payment_id');
    }

    public function journals(): HasMany
    {
        return $this->hasMany(LedgerJournal::class, 'protected_payment_id');
    }

    /**
     * Eligible protected amount for a new release or refund decision: P - D.
     */
    public function eligibleForDecisionMinor(): int
    {
        return max(0, (int) $this->protected_balance_minor - (int) $this->disputed_amount_minor);
    }

    /**
     * Check Rule 36 / SASDS 4.3.6.C conservation of value invariant:
     * F = P + R_L + R_Q + L + Q + V, and 0 <= D <= P.
     */
    public function verifyConservationInvariant(): bool
    {
        $f = (int) $this->funded_amount_minor;
        $p = (int) $this->protected_balance_minor;
        $rl = (int) $this->reserved_release_minor;
        $rq = (int) $this->reserved_refund_minor;
        $l = (int) $this->released_amount_minor;
        $q = (int) $this->refunded_amount_minor;
        $v = (int) $this->reversed_amount_minor;
        $d = (int) $this->disputed_amount_minor;

        if (min($f, $p, $rl, $rq, $l, $q, $v, $d) < 0) {
            return false;
        }

        if ($d > $p) {
            return false;
        }

        return $f === ($p + $rl + $rq + $l + $q + $v);
    }
}
