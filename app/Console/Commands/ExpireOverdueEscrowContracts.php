<?php

namespace App\Console\Commands;

use App\Services\EscrowContractService;
use Illuminate\Console\Command;

class ExpireOverdueEscrowContracts extends Command
{
    protected $signature = 'escrow-contracts:expire-overdue';

    protected $description = 'Expire SHARED escrow contracts whose acceptance window has elapsed (Module 3 auto-expiry)';

    public function handle(EscrowContractService $contractService): int
    {
        $expired = $contractService->expireOverdue();

        $this->info("Expired {$expired} overdue escrow contract(s).");

        return self::SUCCESS;
    }
}
