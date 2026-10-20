<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Escrow contract auto-expiry when the acceptance window elapses (Module 3).
Schedule::command('escrow-contracts:expire-overdue')->hourly();

// Daily close: subledger balance snapshots for reconciliation and month-end.
Schedule::command('ledger:snapshot-subledgers')->dailyAt('23:55');

// Phase 5: bilingual deadline reminders (acceptance, delivery, inspection, payout retry).
Schedule::command('escrow:send-reminders')->hourly();

// Phase 5: three-way escrow invariant check; freezes payouts automatically on breach.
Schedule::command('escrow:reconcile-invariant')->everyThirtyMinutes();
