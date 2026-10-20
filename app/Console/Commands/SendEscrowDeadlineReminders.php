<?php

namespace App\Console\Commands;

use App\Models\EscrowContract;
use App\Models\PayoutInstruction;
use App\Models\ProtectedPayment;
use App\Models\ReminderDispatch;
use App\Models\User;
use App\Services\NotificationOutboxService;
use Illuminate\Console\Command;

/**
 * Bilingual deadline reminders (Module 14).
 *
 * Runs hourly and dispatches at most one reminder per (type, subject, window)
 * and per user, deduped through reminder_dispatches so a cron rerun can never
 * spam a counterparty.
 */
class SendEscrowDeadlineReminders extends Command
{
    protected $signature = 'escrow:send-reminders {--dry-run : Report what would be sent without dispatching}';

    protected $description = 'Send bilingual reminders for acceptance, delivery and inspection deadlines';

    public function handle(NotificationOutboxService $outbox): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        // 1. Acceptance windows closing within 24h
        EscrowContract::where('status', 'SHARED')
            ->whereNotNull('acceptance_expires_at')
            ->whereBetween('acceptance_expires_at', [now(), now()->addHours(24)])
            ->each(function (EscrowContract $contract) use ($outbox, $dryRun, &$sent): void {
                $recipients = array_filter([
                    $contract->counterparty_user_id ? User::find($contract->counterparty_user_id) : null,
                ]);

                foreach ($recipients as $user) {
                    $sent += $this->dispatch(
                        $outbox,
                        $dryRun,
                        'acceptance_closing',
                        'ESCROW_CONTRACT',
                        $contract->id,
                        $user,
                        'Acceptance window closing',
                        "The escrow contract {$contract->reference} expires for acceptance soon. Review and accept it to start protection.",
                        'Muda wa kukubali unakwisha',
                        "Mkataba wa amana {$contract->reference} unakwisha muda wa kukubali hivi karibuni. Pitia na ukubali ili ulinzi uanze."
                    );
                }
            });

        // 2. Delivery deadlines passed without confirmation
        EscrowContract::whereIn('status', ['ACCEPTED'])
            ->whereNotNull('delivery_deadline_at')
            ->whereBetween('delivery_deadline_at', [now()->subDay(), now()])
            ->each(function (EscrowContract $contract) use ($outbox, $dryRun, &$sent): void {
                foreach (array_filter([User::find($contract->initiator_id)]) as $user) {
                    $sent += $this->dispatch(
                        $outbox,
                        $dryRun,
                        'delivery_overdue',
                        'ESCROW_CONTRACT',
                        $contract->id,
                        $user,
                        'Delivery deadline passed',
                        "The delivery deadline for {$contract->reference} has passed. Submit evidence or agree an extension with the other party.",
                        'Muda wa uwasilishaji umepita',
                        "Muda wa uwasilishaji kwa {$contract->reference} umepita. Wasilisha ushahidi au kubaliana nyongeza muda na upande mwingine."
                    );
                }
            });

        // 3. Inspection windows closing (awaiting buyer acceptance)
        ProtectedPayment::where('status', 'AWAITING_ACCEPTANCE')
            ->whereNotNull('auto_release_at')
            ->whereBetween('auto_release_at', [now(), now()->addHours(24)])
            ->each(function (ProtectedPayment $payment) use ($outbox, $dryRun, &$sent): void {
                foreach (array_filter([User::find($payment->buyer_id)]) as $user) {
                    $sent += $this->dispatch(
                        $outbox,
                        $dryRun,
                        'inspection_closing',
                        'PROTECTED_PAYMENT',
                        $payment->id,
                        $user,
                        'Inspection window closing',
                        "Your inspection window for {$payment->reference} closes soon. Accept the goods or open a dispute before it closes.",
                        'Muda wa ukaguzi unakwisha',
                        "Muda wako wa ukaguzi kwa {$payment->reference} unakwisha hivi karibuni. Kubali bidhaa au fungua malalamiko kabla haujakamilika."
                    );
                }
            });

        // 4. Payouts stuck awaiting retry
        PayoutInstruction::where('status', 'PENDING')
            ->whereNotNull('next_retry_at')
            ->where('retry_count', '>=', 1)
            ->each(function (PayoutInstruction $instruction) use ($outbox, $dryRun, &$sent): void {
                $merchant = User::find($instruction->merchant_id);

                if (!$merchant) {
                    return;
                }

                $sent += $this->dispatch(
                    $outbox,
                    $dryRun,
                    'payout_retry_reminder',
                    'PAYOUT_INSTRUCTION',
                    $instruction->id,
                    $merchant,
                    'Payout retry in progress',
                    "Your payout is being retried automatically. Funds remain safe in escrow in the meantime.",
                    'Malipo yanajaribiwa tena',
                    'Malipo yako yanajaribiwa tena kiotomatiki. Pesa zako zinaendelea kuwa salama kwenye amana.'
                );
            });

        $this->info(($dryRun ? '[dry-run] ' : '') . "Reminders dispatched: {$sent}");

        return self::SUCCESS;
    }

    protected function dispatch(
        NotificationOutboxService $outbox,
        bool $dryRun,
        string $type,
        string $subjectType,
        int $subjectId,
        User $user,
        string $titleEn,
        string $messageEn,
        string $titleSw,
        string $messageSw
    ): int {
        $key = implode(':', [$type, $subjectType, $subjectId, $user->id, now()->toDateString()]);

        if (ReminderDispatch::where('reminder_key', $key)->exists()) {
            return 0;
        }

        if (!$dryRun) {
            $outbox->publishEvent(
                aggregateType: $subjectType,
                aggregateId: (string) $subjectId,
                eventType: 'reminder.' . $type,
                payload: ['reminder_type' => $type, 'subject_id' => $subjectId],
                recipientUser: $user,
                titleEn: $titleEn,
                messageEn: $messageEn,
                titleSw: $titleSw,
                messageSw: $messageSw
            );

            ReminderDispatch::create([
                'reminder_key' => $key,
                'reminder_type' => $type,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'user_id' => $user->id,
                'dispatched_at' => now(),
            ]);
        }

        return 1;
    }
}
