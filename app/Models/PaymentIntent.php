<?php

namespace App\Models;

use App\Enums\PaymentIntentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'intent_id',
        'correlation_id',
        'protected_payment_id',
        'fee_quote_id',
        'payer_id',
        'payee_id',
        'currency',
        'amount_minor',
        'principal_amount_minor',
        'buyer_fee_minor',
        'buyer_tax_minor',
        'idempotency_key',
        'request_hash',
        'status',
        'expires_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentIntentStatus::class,
            'amount_minor' => 'integer',
            'principal_amount_minor' => 'integer',
            'buyer_fee_minor' => 'integer',
            'buyer_tax_minor' => 'integer',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class, 'protected_payment_id');
    }

    public function feeQuote(): BelongsTo
    {
        return $this->belongsTo(FeeQuote::class, 'fee_quote_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'payment_intent_id');
    }

    public function latestAttempt(): HasOne
    {
        return $this->hasOne(PaymentAttempt::class, 'payment_intent_id')->latestOfMany();
    }
}
