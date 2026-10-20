<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\AmlRiskRating;
use App\Enums\KycTier;
use App\Enums\KybStatus;
use App\Enums\KycStatus;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Strict mass assignment protection.
     * Sensitive security fields (is_active, account_status, kyc_status, kyb_status, role)
     * are never mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'phone',
        'user_type',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'activation_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'account_status' => AccountStatus::class,
            'user_type' => UserType::class,
            'kyc_status' => KycStatus::class,
            'kyb_status' => KybStatus::class,
            'kyc_tier' => KycTier::class,
            'aml_risk_rating' => AmlRiskRating::class,
            'tier_assessed_at' => 'datetime',
            'is_active' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class);
    }

    public function kycVerifications(): HasMany
    {
        return $this->hasMany(KycVerification::class);
    }

    public function latestKycVerification(): HasOne
    {
        return $this->hasOne(KycVerification::class)->latestOfMany();
    }

    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function paymentLinks(): HasMany
    {
        return $this->hasMany(ProtectedPaymentLink::class, 'merchant_id');
    }

    public function protectedPaymentsAsMerchant(): HasMany
    {
        return $this->hasMany(ProtectedPayment::class, 'merchant_id');
    }

    public function protectedPaymentsAsBuyer(): HasMany
    {
        return $this->hasMany(ProtectedPayment::class, 'buyer_id');
    }

    public function isIndividual(): bool
    {
        return $this->user_type === UserType::Individual;
    }

    public function isBusiness(): bool
    {
        return $this->user_type === UserType::Business;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Check whether the user has completed required KYC (Individual) or KYB (Business)
     * verification to act as a verified party in Protected Payments (BPA-SBV-02, FR-003).
     */
    public function isVerifiedForTrading(): bool
    {
        if (!$this->is_active || $this->account_status !== AccountStatus::Active) {
            return false;
        }

        if ($this->isIndividual()) {
            return $this->kyc_status === KycStatus::Verified;
        }

        return $this->kyb_status === KybStatus::Verified;
    }

    /**
     * Get the public display name and verification badge scope (BPA-SBV-03, SASDS 3.6.4.C).
     *
     * @return array<string, mixed>
     */
    public function publicTrustSummary(): array
    {
        $tradingName = $this->isBusiness() && $this->businessProfile
            ? $this->businessProfile->business_name
            : trim("{$this->first_name} {$this->last_name}");

        return [
            'merchant_id' => $this->id,
            'trading_name' => $tradingName,
            'party_type' => $this->user_type?->value,
            'is_verified' => $this->isVerifiedForTrading(),
            'verification_scope' => $this->isBusiness()
                ? ['IDENTITY_VERIFIED', 'BUSINESS_REGISTRATION_VERIFIED', 'TIN_VERIFIED']
                : ['NIDA_IDENTITY_VERIFIED'],
            'verification_disclaimer' => 'Verification confirms that defined KYC/KYB checks were completed at the stated review date. It is not an unconditional guarantee of future performance.',
        ];
    }
}
