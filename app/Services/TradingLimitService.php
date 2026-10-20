<?php

namespace App\Services;

use App\Enums\AmlRiskRating;
use App\Enums\KycTier;
use App\Models\EscrowContract;
use App\Models\ProtectedPayment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * KYC/AML tier engine (Module 9).
 *
 * Derives a user's trading tier, exposes the resulting limits, and blocks
 * contract creation / acceptance / payout when the amount exceeds the tier's
 * single-transaction or rolling monthly caps.
 */
class TradingLimitService
{
    /**
     * Resolve the effective tier for a user.
     */
    public function tierFor(User $user): KycTier
    {
        if ($user->isAdmin()) {
            return KycTier::Tier3Enhanced;
        }

        $verifiedForTrading = $user->isVerifiedForTrading();
        $higherRisk = $user->aml_risk_rating === AmlRiskRating::High;

        if (!$verifiedForTrading) {
            return KycTier::Tier0Unverified;
        }

        $lifetimeVolume = $this->lifetimeFundedVolumeMinor($user);
        $auto = (array) config('aml_tiers.auto_promotion', []);

        $tier = KycTier::Tier1Basic;

        if ($lifetimeVolume >= (int) ($auto['tier_2_verified_volume_minor'] ?? PHP_INT_MAX)) {
            $tier = KycTier::Tier2Standard;
        }

        if (
            $lifetimeVolume >= (int) ($auto['tier_3_volume_minor'] ?? PHP_INT_MAX)
            && (!$higherRisk)
            && ((bool) ($auto['tier_3_requires_business_kyb'] ?? true) ? $user->isBusiness() : true)
        ) {
            $tier = KycTier::Tier3Enhanced;
        }

        // Never auto-promote an AML high-risk user beyond basic without review.
        if ($higherRisk && $tier->rank() > KycTier::Tier1Basic->rank()) {
            $tier = KycTier::Tier1Basic;
        }

        // An explicitly assigned higher tier (compliance review) wins.
        $stored = $user->kyc_tier instanceof KycTier
            ? $user->kyc_tier
            : KycTier::tryFrom((string) ($user->kyc_tier ?? ''));

        if ($stored && $stored->rank() > $tier->rank() && !$higherRisk) {
            return $stored;
        }

        return $tier;
    }

    /**
     * @return array<string, mixed>
     */
    public function limitsFor(KycTier $tier): array
    {
        $tiers = (array) config('aml_tiers.tiers', []);
        $limits = (array) ($tiers[$tier->value] ?? []);

        return [
            'tier' => $tier->value,
            'label' => $tier->label(),
            'rank' => $tier->rank(),
            'single_transaction_max_minor' => (int) ($limits['single_transaction_max_minor'] ?? 0),
            'monthly_volume_max_minor' => (int) ($limits['monthly_volume_max_minor'] ?? 0),
            'description' => (string) ($limits['description'] ?? ''),
        ];
    }

    /**
     * Full tier summary for the authenticated user.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(User $user): array
    {
        $tier = $this->tierFor($user);
        $limits = $this->limitsFor($tier);
        $used = $this->rollingMonthlyVolumeMinor($user);

        return [
            'user_id' => $user->id,
            'kyc_status' => $user->kyc_status?->value,
            'kyb_status' => $user->kyb_status?->value,
            'aml_risk_rating' => $user->aml_risk_rating?->value ?? 'LOW',
            'tier' => $limits,
            'usage' => [
                'rolling_30_day_volume_minor' => $used,
                'remaining_monthly_capacity_minor' => max(0, $limits['monthly_volume_max_minor'] - $used),
                'lifetime_funded_volume_minor' => $this->lifetimeFundedVolumeMinor($user),
            ],
            'can_trade' => $limits['single_transaction_max_minor'] > 0,
            'next_tier_hint' => $this->nextTierHint($tier),
        ];
    }

    /**
     * Enforce tier limits for a new escrow commitment.
     */
    public function assertCanCommit(User $user, int $amountMinor, string $context = 'escrow_contract'): void
    {
        $tier = $this->tierFor($user);
        $limits = $this->limitsFor($tier);

        if ((int) $limits['single_transaction_max_minor'] <= 0) {
            throw ValidationException::withMessages([
                'amount_minor' => ['Your account is not yet verified for escrow trading. Complete KYC/KYB to unlock protected payments.'],
            ]);
        }

        if ($amountMinor > (int) $limits['single_transaction_max_minor']) {
            throw ValidationException::withMessages([
                'amount_minor' => [sprintf(
                    'This amount exceeds your %s single-transaction limit of TZS %s. Complete a higher KYC tier or split the agreement.',
                    $tier->value,
                    number_format($limits['single_transaction_max_minor'] / 100, 2)
                )],
            ]);
        }

        $monthlyUsed = $this->rollingMonthlyVolumeMinor($user);

        if ($monthlyUsed + $amountMinor > (int) $limits['monthly_volume_max_minor']) {
            throw ValidationException::withMessages([
                'amount_minor' => [sprintf(
                    'This amount would exceed your 30-day %s cap of TZS %s (already committed TZS %s). Complete a higher KYC tier to continue.',
                    $tier->value,
                    number_format($limits['monthly_volume_max_minor'] / 100, 2),
                    number_format($monthlyUsed / 100, 2)
                )],
            ]);
        }
    }

    /**
     * Recommend the AML rating from activity (advisory; compliance confirms).
     */
    public function recommendedRiskRating(User $user): AmlRiskRating
    {
        $openDisputes = \App\Models\DisputeCase::whereHas('protectedPayment', function ($query) use ($user): void {
            $query->where('buyer_id', $user->id)->orWhere('merchant_id', $user->id);
        })->whereIn('status', ['OPEN_FROZEN', 'UNDER_REVIEW', 'APPEALED'])->count();

        $lifetime = $this->lifetimeFundedVolumeMinor($user);
        $threshold = (int) config('aml_tiers.high_risk.manual_review_above_minor', PHP_INT_MAX);

        if ($openDisputes >= 3 || $lifetime >= $threshold) {
            return AmlRiskRating::High;
        }

        return $openDisputes >= 1 ? AmlRiskRating::Medium : AmlRiskRating::Low;
    }

    public function lifetimeFundedVolumeMinor(User $user): int
    {
        return (int) ProtectedPayment::where('buyer_id', $user->id)
            ->orWhere('merchant_id', $user->id)
            ->sum('funded_amount_minor');
    }

    public function rollingMonthlyVolumeMinor(User $user): int
    {
        $since = now()->subDays(30);

        return (int) EscrowContract::where('created_at', '>=', $since)
            ->where(function ($query) use ($user): void {
                $query->where('initiator_id', $user->id)->orWhere('counterparty_user_id', $user->id);
            })
            ->whereNotIn('status', ['CANCELLED', 'EXPIRED', 'REJECTED'])
            ->sum('amount_minor');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function nextTierHint(KycTier $tier): ?array
    {
        $next = KycTier::fromRank($tier->rank() + 1);

        if ($next->rank() === $tier->rank()) {
            return null;
        }

        $limits = $this->limitsFor($next);

        return [
            'tier' => $limits['tier'],
            'single_transaction_max_minor' => $limits['single_transaction_max_minor'],
            'requirement' => $next === KycTier::Tier2Standard
                ? 'Accumulate verified escrow volume and declare source of funds.'
                : 'Complete business KYB and enhanced due diligence.',
        ];
    }
}
