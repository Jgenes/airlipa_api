<?php

namespace App\Console\Commands;

use App\Services\SubledgerService;
use Illuminate\Console\Command;

class SnapshotSubledgerBalances extends Command
{
    protected $signature = 'ledger:snapshot-subledgers {--date= : As-of date (Y-m-d), defaults to today}';

    protected $description = 'Write daily close balance snapshots for contract and party subledgers';

    public function handle(SubledgerService $subledgerService): int
    {
        $written = $subledgerService->snapshotBalances($this->option('date') ?: null);

        $this->info("Wrote {$written} balance snapshot(s).");

        return self::SUCCESS;
    }
}
