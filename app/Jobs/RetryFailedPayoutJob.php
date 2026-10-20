<?php

namespace App\Jobs;

use App\Models\PayoutInstruction;
use App\Services\PayoutSettlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Exponential-backoff retry for failed Selcom disbursements (Module 5).
 *
 * Each failed payout reverses its subledger reservation first (funds stay in
 * escrow), then this job re-reserves and retries the disbursement. After the
 * final attempt the instruction is BLOCKED and escalated to Admin/Finance.
 */
class RetryFailedPayoutJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Backoff schedule in seconds: 1min, 5min, 25min. */
    public const BACKOFF_SECONDS = [60, 300, 1500];

    public int $tries = 4;

    public function __construct(public int $payoutInstructionId)
    {
        $this->onQueue('payouts');
    }

    public function handle(PayoutSettlementService $settlementService): void
    {
        $instruction = PayoutInstruction::with(['protectedPayment', 'payoutAccount'])->find($this->payoutInstructionId);

        if (!$instruction || $instruction->status === 'SETTLED') {
            return;
        }

        try {
            $settlementService->retryPayoutAttempt($instruction);
        } catch (Throwable $exception) {
            Log::warning('payout_retry_attempt_failed', [
                'payout_instruction_id' => $instruction->payout_instruction_id,
                'retry_count' => (int) $instruction->retry_count,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Seconds to wait before the next retry attempt (exponential backoff).
     */
    public static function backoffForAttempt(int $retryCount): int
    {
        return self::BACKOFF_SECONDS[min($retryCount, count(self::BACKOFF_SECONDS) - 1)];
    }
}
