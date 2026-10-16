<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'correlation_id',
        'provider_code',
        'provider_transaction_id',
        'event_type',
        'payment_attempt_id',
        'payment_intent_id',
        'currency',
        'amount_minor',
        'signature_key_id',
        'message_hash',
        'signature_valid',
        'processing_status',
        'raw_payload',
        'occurred_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'signature_valid' => 'boolean',
            'raw_payload' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }
}
