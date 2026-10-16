<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationException extends Model
{
    use HasFactory;

    protected $fillable = [
        'exception_id',
        'correlation_id',
        'protected_payment_id',
        'payment_intent_id',
        'provider_code',
        'provider_reference',
        'category',
        'severity',
        'status',
        'details',
        'metadata',
        'sla_due_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'sla_due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class, 'protected_payment_id');
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }
}
