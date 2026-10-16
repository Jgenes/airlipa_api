<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FeeQuote extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id',
        'correlation_id',
        'pricing_version',
        'created_by_user_id',
        'merchant_id',
        'buyer_id',
        'transaction_type',
        'currency',
        'principal_amount_minor',
        'buyer_fee_minor',
        'buyer_tax_minor',
        'merchant_fee_minor',
        'merchant_tax_minor',
        'total_payable_minor',
        'merchant_net_minor',
        'fee_lines',
        'approval_references',
        'terms_version',
        'disclosure_hash',
        'status',
        'accepted_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'principal_amount_minor' => 'integer',
            'buyer_fee_minor' => 'integer',
            'buyer_tax_minor' => 'integer',
            'merchant_fee_minor' => 'integer',
            'merchant_tax_minor' => 'integer',
            'total_payable_minor' => 'integer',
            'merchant_net_minor' => 'integer',
            'fee_lines' => 'array',
            'approval_references' => 'array',
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function protectedPayment(): HasOne
    {
        return $this->hasOne(ProtectedPayment::class, 'fee_quote_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast() && $this->status === 'CREATED';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'ACCEPTED';
    }
}
