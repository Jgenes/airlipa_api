<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantPayoutAccount extends Model
{
    protected $fillable = [
        'payout_account_id',
        'merchant_id',
        'account_type',
        'provider_code',
        'account_name',
        'account_number',
        'account_number_hash',
        'currency',
        'is_default',
        'verification_status',
        'verified_at',
        'cooling_off_until',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_default' => 'boolean',
            'verified_at' => 'datetime',
            'cooling_off_until' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function payoutInstructions(): HasMany
    {
        return $this->hasMany(PayoutInstruction::class, 'payout_account_id');
    }

    public function maskedAccountNumber(): string
    {
        $raw = (string) $this->account_number;
        $len = strlen($raw);
        if ($len <= 4) {
            return '****';
        }

        return str_repeat('*', max(4, $len - 4)) . substr($raw, -4);
    }
}
