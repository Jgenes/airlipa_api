<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProviderCapability extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_code',
        'provider_name',
        'legal_entity',
        'provider_type',
        'license_reference',
        'custody_account_reference',
        'supported_currencies',
        'min_amount_minor',
        'max_amount_minor',
        'supports_protected_hold',
        'supports_refund',
        'supports_payout',
        'is_active',
        'webhook_secret',
        'approval_reference',
        'expires_at',
    ];

    protected $hidden = [
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'supported_currencies' => 'array',
            'min_amount_minor' => 'integer',
            'max_amount_minor' => 'integer',
            'supports_protected_hold' => 'boolean',
            'supports_refund' => 'boolean',
            'supports_payout' => 'boolean',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function isEligibleFor(string $currency, int $amountMinor, bool $requireProtectedHold = true): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($requireProtectedHold && !$this->supports_protected_hold) {
            return false;
        }

        $currencies = is_array($this->supported_currencies) ? $this->supported_currencies : [];
        if (!in_array(strtoupper($currency), $currencies, true)) {
            return false;
        }

        return $amountMinor >= $this->min_amount_minor && $amountMinor <= $this->max_amount_minor;
    }
}
