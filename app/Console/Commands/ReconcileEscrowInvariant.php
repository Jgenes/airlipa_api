<?php

namespace App\Console\Commands;

use App\Services\EscrowReconciliationService;
use App\Services\PayoutFreezeService;
use Illuminate\Console\Command;

/**
 * Scheduled three-way escrow invariant check (Module 11).
 *
 * On breach it raises a CRITICAL exception and freezes payouts; on a healthy
 * run it does NOT auto-release a freeze (that stays a human decision).
 */
class ReconcileEscrowInvariant extends Command
{
    protected $signature = 'escrow:reconcile-invariant {--currency=TZS} {--dry-run : Compute the invariant without raising exceptions or freezing payouts}';

    protected $description = 'Verify subledger = GL escrow control = partner statement and freeze payouts on breach';

    public function handle(EscrowReconciliationService $reconciliation, PayoutFreezeService $freeze): int
    {
        if ($this->option('dry-run')) {
            $result = $reconciliation->threeWayInvariant((string) $this->option('currency'));

            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $actor = \App\Models\User::where('role', 'admin')->orderBy('id')->first();

        if (!$actor) {
            $this->warn('No administrator exists to attribute the invariant check to; skipping automatic freeze attribution.');

            $result = $reconciliation->threeWayInvariant((string) $this->option('currency'));

            if (!$result['healthy']) {
                $freeze->activate(null, 'Escrow invariant breached (system check).', $result['variance']);
                $this->error('Invariant breached; payouts frozen.');

                return self::FAILURE;
            }

            $this->info('Escrow invariant holds.');

            return self::SUCCESS;
        }

        $result = $reconciliation->runInvariantCheck($actor);

        if ($result['healthy']) {
            $this->info('Escrow invariant holds; payouts not frozen.');

            return self::SUCCESS;
        }

        $this->error('Escrow invariant breached: ' . implode(' ', $result['violations'] ?? []));
        $this->error('Payouts frozen. Investigate the CRITICAL reconciliation exception.');

        return self::FAILURE;
    }
}
