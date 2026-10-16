<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentLinkStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProtectedPaymentLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'link_id',
        'token',
        'correlation_id',
        'merchant_id',
        'title',
        'description',
        'transaction_type',
        'currency',
        'principal_amount_minor',
        'delivery_method',
        'delivery_days',
        'auto_release_hours',
        'release_conditions',
        'return_policy',
        'permitted_buyer_phone',
        'max_uses',
        'uses_count',
        'status',
        'expires_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'delivery_method' => DeliveryMethod::class,
            'status' => PaymentLinkStatus::class,
            'principal_amount_minor' => 'integer',
            'delivery_days' => 'integer',
            'auto_release_hours' => 'integer',
            'release_conditions' => 'array',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function protectedPayments(): HasMany
    {
        return $this->hasMany(ProtectedPayment::class, 'payment_link_id');
    }

    public function isUsable(): bool
    {
        if ($this->status !== PaymentLinkStatus::Active) {
            return false;
        }

        if ($this->revoked_at !== null || $this->expires_at->isPast()) {
            return false;
        }

        return $this->uses_count < $this->max_uses;
    }

    public function getCanonicalUrlAttribute(): string
    {
        $domain = rtrim((string) config('protected_payment.canonical_link_domain', 'https://pay.airlipa.co.tz'), '/');
        return "{$domain}/l/{$this->token}";
    }
}
