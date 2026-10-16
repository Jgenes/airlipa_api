<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'correlation_id',
        'payment_intent_id',
        'provider_code',
        'payment_method',
        'payer_instrument',
        'currency',
        'amount_minor',
        'idempotency_key',
        'provider_reference',
        'raw_provider_status',
        'normalized_status',
        'failure_reason',
        'request_hash',
        'provider_payload',
        'submitted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'provider_payload' => 'array',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function isUncertain(): bool
    {
        return in_array($this->normalized_status, ['SUBMITTED', 'PENDING', 'UNKNOWN'], true);
    }
}
