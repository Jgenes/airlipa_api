<?php

namespace App\Services;

use App\Models\PaymentProviderCapability;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ProviderRegistryService
{
    /**
     * Ensure default approved licensed provider capabilities exist in the database (M18).
     */
    public function ensureDefaultProvidersExist(): void
    {
        if (PaymentProviderCapability::count() > 0) {
            return;
        }

        $defaults = (array) config('protected_payment.default_providers', []);

        foreach ($defaults as $provider) {
            PaymentProviderCapability::firstOrCreate(
                ['provider_code' => $provider['provider_code']],
                $provider
            );
        }
    }

    /**
     * Retrieve all active approved payment providers.
     *
     * @return Collection<int, PaymentProviderCapability>
     */
    public function getActiveProviders(): Collection
    {
        $this->ensureDefaultProvidersExist();

        return PaymentProviderCapability::where('is_active', true)
            ->orderBy('provider_code')
            ->get();
    }

    /**
     * Find and validate a provider capability for routing (FR-008, FR-M18-03, FR-M18-04).
     */
    public function resolveApprovedCapability(
        string $providerCode,
        string $currency,
        int $amountMinor,
        bool $requireProtectedHold = true
    ): PaymentProviderCapability {
        $this->ensureDefaultProvidersExist();

        $capability = PaymentProviderCapability::where('provider_code', strtoupper(trim($providerCode)))->first();

        if (!$capability) {
            throw ValidationException::withMessages([
                'provider_code' => ["Payment provider '{$providerCode}' is not registered in the M18 Partner & Product Governance registry."],
            ]);
        }

        if (!$capability->is_active) {
            throw ValidationException::withMessages([
                'provider_code' => ["Payment provider '{$providerCode}' is currently suspended and cannot accept new payment traffic."],
            ]);
        }

        if ($capability->expires_at !== null && $capability->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'provider_code' => ["Payment provider capability '{$providerCode}' approval has expired."],
            ]);
        }

        if (!$capability->isEligibleFor($currency, $amountMinor, $requireProtectedHold)) {
            throw ValidationException::withMessages([
                'provider_code' => ["Payment provider '{$providerCode}' does not support currency '{$currency}' or amount '{$amountMinor}' minor units."],
            ]);
        }

        return $capability;
    }

    /**
     * Retrieve a single provider capability by code.
     */
    public function getCapability(string $providerCode): PaymentProviderCapability
    {
        $this->ensureDefaultProvidersExist();

        return PaymentProviderCapability::where('provider_code', strtoupper(trim($providerCode)))->firstOrFail();
    }

    /**
     * Assert that a provider capability is active and supports merchant payouts (M14 / M18).
     */
    public function assertCanPayout(string $providerCode): PaymentProviderCapability
    {
        $this->ensureDefaultProvidersExist();

        $capability = PaymentProviderCapability::where('provider_code', strtoupper(trim($providerCode)))->first();

        if (! $capability || ! $capability->is_active || ! $capability->supports_payout) {
            throw ValidationException::withMessages([
                'provider_code' => ["Payment provider '{$providerCode}' is not active or does not support merchant payouts."],
            ]);
        }

        return $capability;
    }

    /**
     * Emergency operational control: suspend or reactivate a provider capability (FR-037, FR-M18-06).
     * Stopping new transactions does not delete or block existing open obligations.
     */
    public function updateCapabilityStatus(
        string $providerCode,
        bool $isActive,
        User $admin,
        string $reason
    ): PaymentProviderCapability {
        $this->ensureDefaultProvidersExist();

        $capability = PaymentProviderCapability::where('provider_code', strtoupper(trim($providerCode)))->firstOrFail();

        $capability->update([
            'is_active' => $isActive,
        ]);

        AuditLogService::log(
            event: $isActive ? 'provider_capability_activated' : 'provider_capability_suspended',
            user: $admin,
            metadata: [
                'provider_code' => $capability->provider_code,
                'is_active' => $isActive,
                'reason' => $reason,
            ]
        );

        return $capability->fresh();
    }
}
