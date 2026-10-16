<?php

namespace Database\Seeders;

use App\Services\LedgerService;
use App\Services\PricingService;
use App\Services\ProviderRegistryService;
use Illuminate\Database\Seeder;

class ProtectedPaymentFoundationSeeder extends Seeder
{
    /**
     * Seed M18 Provider Capabilities, M15 Price Book Rules, and M08 Chart of Accounts.
     */
    public function run(
        ProviderRegistryService $providerRegistry,
        PricingService $pricingService,
        LedgerService $ledgerService
    ): void {
        $providerRegistry->ensureDefaultProvidersExist();
        $pricingService->ensureDefaultFeeRulesExist();
        $ledgerService->ensureChartOfAccountsExists('TZS');
    }
}
