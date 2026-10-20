<?php

namespace App\Services;

use App\Enums\DisputeStatus;
use App\Enums\SettlementStatus;
use App\Jobs\RetryFailedPayoutJob;
use App\Models\ApprovalRequest;
use App\Models\MerchantPayoutAccount;
use App\Models\PayoutInstruction;
use App\Models\ProtectedPayment;
use App\Models\SettlementBatch;
use App\Models\User;
use App\Services\PaymentProviders\PaymentProviderAdapterInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PayoutSettlementService
{
    public function __construct(
        private readonly ProviderRegistryService $providerRegistry,
        private readonly PaymentProviderAdapterInterface $providerAdapter,
        private readonly LedgerService $ledgerService,
        private readonly NotificationOutboxService $outboxService,
        private readonly SubledgerService $subledgerService
    ) {}

    /**
     * Register a verified merchant payout account with step-up password authentication (M14, BPA-DATA-03).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerPayoutAccount(User $merchant, array $attributes): MerchantPayoutAccount
    {
        if (! $merchant->isVerifiedForTrading()) {
            throw new AccessDeniedHttpException('Only verified merchants (KYC/KYB complete) can register payout accounts.');
        }

        if (! Hash::check((string) ($attributes['step_up_password'] ?? ''), $merchant->password)) {
            throw new AccessDeniedHttpException('Step-up authentication failed: invalid account password.');
        }

        $providerCode = strtoupper((string) $attributes['provider_code']);
        $this->providerRegistry->assertCanPayout($providerCode);

        $accountNumber = preg_replace('/\s+/', '', (string) $attributes['account_number']);
        $accountNumberHash = hash('sha256', $providerCode . ':' . $accountNumber);

        return DB::transaction(function () use ($merchant, $attributes, $providerCode, $accountNumber, $accountNumberHash) {
            $hasExistingDefault = MerchantPayoutAccount::where('merchant_id', $merchant->id)
                ->where('is_default', true)
                ->exists();

            $isDefault = (bool) ($attributes['is_default'] ?? (! $hasExistingDefault));

            if ($isDefault) {
                MerchantPayoutAccount::where('merchant_id', $merchant->id)
                    ->update(['is_default' => false]);
            }

            $account = MerchantPayoutAccount::create([
                'payout_account_id' => (string) Str::uuid(),
                'merchant_id' => $merchant->id,
                'account_type' => strtoupper((string) ($attributes['account_type'] ?? 'MNO_WALLET')),
                'provider_code' => $providerCode,
                'account_name' => $attributes['account_name'],
                'account_number' => $accountNumber,
                'account_number_hash' => $accountNumberHash,
                'currency' => strtoupper((string) ($attributes['currency'] ?? 'TZS')),
                'is_default' => $isDefault,
                'verification_status' => 'VERIFIED',
                'verified_at' => now(),
                'cooling_off_until' => null,
            ]);

            AuditLogService::log('payout_account.registered', $merchant, [
                'payout_account_id' => $account->payout_account_id,
                'provider_code' => $providerCode,
                'account_type' => $account->account_type,
                'is_default' => $isDefault,
            ]);

            return $account;
        });
    }

    /**
     * Execute merchant payout settlement for a released ProtectedPayment (M14, FR-016, FR-017, FR-018).
     * Strictly enforces that disputed or unreleased funds can never be settled.
     *
     * @return array{idempotent_replay: bool, instruction: PayoutInstruction, batch: SettlementBatch}
     */
    public function settleMerchantPayout(
        ProtectedPayment $payment,
        User $actor,
        string $idempotencyKey,
        ?string $payoutAccountId = null,
        array $data = []
    ): array {
        if ($actor->id !== $payment->merchant_id && ! $actor->isAdmin()) {
            throw new AccessDeniedHttpException('Only the merchant or an authorized administrator can trigger payout settlement.');
        }

        // Check idempotency first
        $existing = PayoutInstruction::where('merchant_id', $payment->merchant_id)
            ->where('idempotency_key', $idempotencyKey)
            ->with(['settlementBatch', 'payoutAccount', 'ledgerJournal'])
            ->first();

        if ($existing) {
            return [
                'idempotent_replay' => true,
                'instruction' => $existing,
                'batch' => $existing->settlementBatch,
            ];
        }

        return DB::transaction(function () use ($payment, $actor, $idempotencyKey, $payoutAccountId, $data) {
            $lockedPayment = ProtectedPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            app(\App\Services\PayoutFreezeService::class)->assertNotFrozen();

            if (in_array($lockedPayment->dispute_status, [DisputeStatus::OpenFrozen, DisputeStatus::UnderReview, DisputeStatus::Appealed], true)) {
                throw new ConflictHttpException('Payout settlement is blocked while a dispute is open on this Protected Payment (FR-014).');
            }

            if ((int) $lockedPayment->released_amount_minor <= 0) {
                throw new ConflictHttpException('No released principal is eligible for merchant payout settlement yet.');
            }

            // Determine how much has already been settled via previous PayoutInstructions
            $alreadySettledGross = (int) PayoutInstruction::where('protected_payment_id', $lockedPayment->id)
                ->where('status', 'SETTLED')
                ->sum('gross_released_minor');

            $unsettledReleasedMinor = (int) $lockedPayment->released_amount_minor - $alreadySettledGross;

            if ($unsettledReleasedMinor <= 0) {
                throw new ConflictHttpException('All released funds for this Protected Payment have already been settled to the merchant.');
            }

            // Resolve merchant payout account
            $payoutAccountQuery = MerchantPayoutAccount::where('merchant_id', $lockedPayment->merchant_id)
                ->where('verification_status', 'VERIFIED');

            if ($payoutAccountId !== null) {
                $payoutAccount = (clone $payoutAccountQuery)->where('payout_account_id', $payoutAccountId)->first();
            } else {
                $payoutAccount = (clone $payoutAccountQuery)->where('is_default', true)->first()
                    ?? $payoutAccountQuery->first();
            }

            if (! $payoutAccount) {
                throw new UnprocessableEntityHttpException('Merchant does not have a verified payout account registered. Please register a payout account first.');
            }

            // Verified destination name must match the merchant's KYC/KYB name (Module 5, AML).
            if ((bool) config('protected_payment.payout_name_match_required', true)) {
                $this->assertDestinationNameMatchesKyc($lockedPayment->merchant, (string) $payoutAccount->account_name);
            }

            $capability = $this->providerRegistry->assertCanPayout($payoutAccount->provider_code);

            // Compute proportional merchant fee & VAT for the released slice
            $principalTotal = max(1, (int) $lockedPayment->principal_amount_minor);
            $merchantFeeSlice = (int) intdiv((int) $lockedPayment->merchant_fee_minor * $unsettledReleasedMinor, $principalTotal);
            $merchantTaxSlice = (int) intdiv((int) $lockedPayment->merchant_tax_minor * $unsettledReleasedMinor, $principalTotal);
            $netPayoutMinor = max(0, $unsettledReleasedMinor - $merchantFeeSlice - $merchantTaxSlice);

            // Step-up authentication for high-value payouts (BPA-PER-06).
            if ($netPayoutMinor >= (int) config('protected_payment.payout_step_up_threshold_minor', 100000000)) {
                $this->assertStepUp($actor, $lockedPayment, $data);
            }

            // Maker-checker for large payouts (P-05, FR-036): an independent
            // checker must have approved this specific payout beforehand.
            if ($netPayoutMinor >= (int) config('protected_payment.dual_control_threshold_minor', 500000000)) {
                $this->assertDualControlApproval($lockedPayment);
            }

            $providerResult = $this->providerAdapter->executeRelease(
                payment: $lockedPayment,
                capability: $capability,
                amountMinor: $netPayoutMinor,
                idempotencyKey: "settle_{$lockedPayment->protected_payment_id}_{$idempotencyKey}"
            );

            if (($providerResult['executed'] ?? false) === false) {
                $failedInstruction = PayoutInstruction::create([
                    'payout_instruction_id' => 'po_' . Str::lower(Str::random(20)),
                    'settlement_batch_id' => null,
                    'protected_payment_id' => $lockedPayment->id,
                    'merchant_id' => $lockedPayment->merchant_id,
                    'payout_account_id' => $payoutAccount->id,
                    'provider_code' => $payoutAccount->provider_code,
                    'currency' => $lockedPayment->currency,
                    'gross_released_minor' => $unsettledReleasedMinor,
                    'merchant_fee_minor' => $merchantFeeSlice,
                    'merchant_tax_minor' => $merchantTaxSlice,
                    'net_payout_minor' => $netPayoutMinor,
                    'idempotency_key' => $idempotencyKey,
                    'provider_payout_reference' => $providerResult['provider_release_reference'] ?? null,
                    'status' => 'PENDING',
                    'retry_count' => 0,
                    'next_retry_at' => now()->addSeconds(RetryFailedPayoutJob::backoffForAttempt(0)),
                    'last_failure_reason' => (string) ($providerResult['raw_status'] ?? 'PROVIDER_REJECTED'),
                ]);

                RetryFailedPayoutJob::dispatch($failedInstruction->id)
                    ->delay(now()->addSeconds(RetryFailedPayoutJob::backoffForAttempt(0)));

                AuditLogService::log('payout.scheduled_for_retry', $actor, [
                    'payout_instruction_id' => $failedInstruction->payout_instruction_id,
                    'protected_payment_id' => $lockedPayment->protected_payment_id,
                    'net_payout_minor' => $netPayoutMinor,
                    'failure_reason' => $providerResult['raw_status'] ?? null,
                ]);

                return [
                    'idempotent_replay' => false,
                    'instruction' => $failedInstruction->load(['payoutAccount']),
                    'batch' => null,
                    'retry_scheduled' => true,
                ];
            }

            $providerReference = $providerResult['provider_release_reference'];

            $batch = SettlementBatch::create([
                'batch_reference' => 'BATCH-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
                'provider_code' => $payoutAccount->provider_code,
                'currency' => $lockedPayment->currency,
                'instructions_count' => 1,
                'total_amount_minor' => $netPayoutMinor,
                'status' => 'COMPLETED',
                'triggered_by_user_id' => $actor->id,
                'settled_at' => now(),
            ]);

            $journal = $this->ledgerService->postMerchantPayoutSettlement(
                payment: $lockedPayment,
                netPayoutMinor: $netPayoutMinor,
                providerReference: $providerReference,
                idempotencyKey: $idempotencyKey,
                actor: $actor
            );

            $instruction = PayoutInstruction::create([
                'payout_instruction_id' => 'po_' . Str::lower(Str::random(20)),
                'settlement_batch_id' => $batch->id,
                'protected_payment_id' => $lockedPayment->id,
                'merchant_id' => $lockedPayment->merchant_id,
                'payout_account_id' => $payoutAccount->id,
                'provider_code' => $payoutAccount->provider_code,
                'currency' => $lockedPayment->currency,
                'gross_released_minor' => $unsettledReleasedMinor,
                'merchant_fee_minor' => $merchantFeeSlice,
                'merchant_tax_minor' => $merchantTaxSlice,
                'net_payout_minor' => $netPayoutMinor,
                'idempotency_key' => $idempotencyKey,
                'provider_payout_reference' => $providerReference,
                'status' => 'SETTLED',
                'ledger_journal_id' => $journal->id,
                'executed_at' => now(),
            ]);

            $lockedPayment->update([
                'settlement_status' => SettlementStatus::Settled,
            ]);

            if ($lockedPayment->escrowContract) {
                $this->subledgerService->confirmPayoutSettlement(
                    contract: $lockedPayment->escrowContract,
                    payment: $lockedPayment,
                    amountMinor: $netPayoutMinor,
                    baseKey: 'payout:' . $instruction->payout_instruction_id
                );
            }

            $this->outboxService->publishEvent(
                aggregateType: 'PAYOUT_INSTRUCTION',
                aggregateId: $instruction->payout_instruction_id,
                eventType: 'PAYOUT_SETTLED',
                payload: [
                    'payout_instruction_id' => $instruction->payout_instruction_id,
                    'protected_payment_id' => $lockedPayment->protected_payment_id,
                    'net_payout_minor' => $netPayoutMinor,
                    'currency' => $lockedPayment->currency,
                    'provider_code' => $payoutAccount->provider_code,
                    'provider_payout_reference' => $providerReference,
                ],
                recipientUser: $lockedPayment->merchant,
                titleEn: 'Merchant Settlement Payout Completed',
                messageEn: "Your settlement payout of {$netPayoutMinor} minor units ({$lockedPayment->currency}) for {$lockedPayment->reference} has been disbursed via {$payoutAccount->provider_code}.",
                titleSw: 'Malipo ya Muuzaji Yamekamilika',
                messageSw: "Malipo yako ya {$netPayoutMinor} ({$lockedPayment->currency}) kwa ajili ya {$lockedPayment->reference} yametumwa kupitia {$payoutAccount->provider_code}."
            );

            AuditLogService::log('payout.settled', $actor, [
                'payout_instruction_id' => $instruction->payout_instruction_id,
                'protected_payment_id' => $lockedPayment->protected_payment_id,
                'net_payout_minor' => $netPayoutMinor,
                'provider_code' => $payoutAccount->provider_code,
            ]);

            return [
                'idempotent_replay' => false,
                'instruction' => $instruction->load(['settlementBatch', 'payoutAccount', 'ledgerJournal']),
                'batch' => $batch,
            ];
        });
    }

    /**
     * Retry a failed payout instruction using the SAME idempotency key so the
     * provider can never produce two disbursements (SASDS 4.3.8).
     */
    public function retryPayoutAttempt(PayoutInstruction $instruction): PayoutInstruction
    {
        return DB::transaction(function () use ($instruction): PayoutInstruction {
            $instruction = PayoutInstruction::whereKey($instruction->id)->lockForUpdate()->firstOrFail();

            if (in_array($instruction->status, ['SETTLED', 'BLOCKED'], true)) {
                return $instruction;
            }

            $payment = ProtectedPayment::whereKey($instruction->protected_payment_id)->firstOrFail();
            $payoutAccount = $instruction->payoutAccount;

            if (! $payoutAccount || $payoutAccount->verification_status !== 'VERIFIED') {
                return $this->blockInstruction($instruction, 'Payout destination is no longer verified.');
            }

            $capability = $this->providerRegistry->assertCanPayout($payoutAccount->provider_code);

            $providerResult = $this->providerAdapter->executeRelease(
                payment: $payment,
                capability: $capability,
                amountMinor: (int) $instruction->net_payout_minor,
                idempotencyKey: 'settle_' . $payment->protected_payment_id . '_' . $instruction->idempotency_key
            );

            if (($providerResult['executed'] ?? false) === false) {
                $retryCount = (int) $instruction->retry_count + 1;

                if ($retryCount >= (int) config('protected_payment.payout_max_retries', 3)) {
                    return $this->blockInstruction($instruction, (string) ($providerResult['raw_status'] ?? 'PROVIDER_REJECTED'), $retryCount);
                }

                $instruction->update([
                    'retry_count' => $retryCount,
                    'next_retry_at' => now()->addSeconds(RetryFailedPayoutJob::backoffForAttempt($retryCount)),
                    'last_failure_reason' => (string) ($providerResult['raw_status'] ?? 'PROVIDER_REJECTED'),
                ]);

                RetryFailedPayoutJob::dispatch($instruction->id)
                    ->delay(now()->addSeconds(RetryFailedPayoutJob::backoffForAttempt($retryCount)));

                return $instruction->fresh();
            }

            $providerReference = (string) ($providerResult['provider_release_reference'] ?? '');

            $batch = SettlementBatch::create([
                'batch_reference' => 'BATCH-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
                'provider_code' => $payoutAccount->provider_code,
                'currency' => $payment->currency,
                'instructions_count' => 1,
                'total_amount_minor' => (int) $instruction->net_payout_minor,
                'status' => 'COMPLETED',
                'triggered_by_user_id' => null,
                'settled_at' => now(),
            ]);

            $journal = $this->ledgerService->postMerchantPayoutSettlement(
                payment: $payment,
                netPayoutMinor: (int) $instruction->net_payout_minor,
                providerReference: $providerReference,
                idempotencyKey: $instruction->idempotency_key
            );

            $instruction->update([
                'settlement_batch_id' => $batch->id,
                'provider_payout_reference' => $providerReference,
                'status' => 'SETTLED',
                'ledger_journal_id' => $journal->id,
                'executed_at' => now(),
                'next_retry_at' => null,
                'last_failure_reason' => null,
            ]);

            $payment->update(['settlement_status' => SettlementStatus::Settled]);

            if ($payment->escrowContract) {
                $this->subledgerService->confirmPayoutSettlement(
                    contract: $payment->escrowContract,
                    payment: $payment,
                    amountMinor: (int) $instruction->net_payout_minor,
                    baseKey: 'payout:' . $instruction->payout_instruction_id
                );
            }

            $this->outboxService->publishEvent(
                aggregateType: 'PAYOUT_INSTRUCTION',
                aggregateId: $instruction->payout_instruction_id,
                eventType: 'PAYOUT_SETTLED_AFTER_RETRY',
                payload: [
                    'payout_instruction_id' => $instruction->payout_instruction_id,
                    'protected_payment_id' => $payment->protected_payment_id,
                    'net_payout_minor' => (int) $instruction->net_payout_minor,
                ],
                recipientUser: $payment->merchant,
                titleEn: 'Settlement payout completed',
                messageEn: "Your payout for {$payment->reference} succeeded after a retry.",
                titleSw: 'Malipo yamekamilika',
                messageSw: "Malipo yako kwa {$payment->reference} yamefanikiwa baada ya kujaribu tena."
            );

            AuditLogService::log('payout.settled_after_retry', null, [
                'payout_instruction_id' => $instruction->payout_instruction_id,
                'retry_count' => (int) $instruction->retry_count,
                'provider_payout_reference' => $providerReference,
            ]);

            return $instruction->fresh(['settlementBatch', 'payoutAccount', 'ledgerJournal']);
        });
    }

    /**
     * Escalate a payout that exhausted its retries to Finance/Admin review.
     */
    protected function blockInstruction(PayoutInstruction $instruction, string $reason, ?int $retryCount = null): PayoutInstruction
    {
        $instruction->update([
            'status' => 'BLOCKED',
            'retry_count' => $retryCount ?? (int) $instruction->retry_count,
            'next_retry_at' => null,
            'last_failure_reason' => substr($reason, 0, 500),
        ]);

        AuditLogService::log('payout.blocked_exhausted', null, [
            'payout_instruction_id' => $instruction->payout_instruction_id,
            'reason' => $reason,
        ]);

        if ($instruction->protectedPayment?->merchant) {
            $this->outboxService->publishEvent(
                aggregateType: 'PAYOUT_INSTRUCTION',
                aggregateId: $instruction->payout_instruction_id,
                eventType: 'PAYOUT_BLOCKED',
                payload: ['payout_instruction_id' => $instruction->payout_instruction_id, 'reason' => $reason],
                recipientUser: $instruction->protectedPayment->merchant,
                titleEn: 'Payout under review',
                messageEn: 'Your payout could not be completed automatically and is now under Finance review. Your funds remain safe in escrow.',
                titleSw: 'Malipo yanayokaguliwa',
                messageSw: 'Malipo yako hayakukamilika kiotomatiki na sasa yanakaguliwa na Fedha. Pesa zako ziko salama kwenye amana.'
            );
        }

        return $instruction->fresh();
    }

    /**
     * Destination account name must match the merchant's verified KYC/KYB name.
     */
    protected function assertDestinationNameMatchesKyc(User $merchant, string $accountName): void
    {
        $merchant->loadMissing('businessProfile');

        $expected = $merchant->isBusiness() && $merchant->businessProfile
            ? (string) $merchant->businessProfile->business_name
            : trim($merchant->first_name . ' ' . $merchant->last_name);

        $normalize = static fn (string $value): array => array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($value)) ?: [],
            static fn (string $token): bool => $token !== ''
        ));

        $expectedTokens = $normalize($expected);
        $actualTokens = $normalize($accountName);

        sort($expectedTokens);
        sort($actualTokens);

        if ($expectedTokens !== $actualTokens) {
            throw new UnprocessableEntityHttpException(
                "Verified destination name mismatch: the payout account name '{$accountName}' does not match the KYC/KYB name on file. "
                . 'Funds cannot be sent to a third party (AML requirement). Update the payout account name to match your verified identity.'
            );
        }
    }

    /**
     * Step-up authentication for high-value payouts.
     *
     * @param array<string, mixed> $data
     */
    protected function assertStepUp(User $actor, ProtectedPayment $payment, array $data): void
    {
        $password = (string) ($data['step_up_password'] ?? '');

        if ($password !== '' && Hash::check($password, $actor->password)) {
            return;
        }

        $handoverOtp = (string) ($data['handover_otp'] ?? '');

        if ($handoverOtp !== '' && $payment->handover_otp_hash && Hash::check($handoverOtp, $payment->handover_otp_hash)) {
            return;
        }

        throw new UnprocessableEntityHttpException(
            'Step-up authentication is required for this high-value payout: provide step_up_password or handover_otp.'
        );
    }

    /**
     * Large payouts require a prior independent maker-checker approval (P-05).
     */
    protected function assertDualControlApproval(ProtectedPayment $payment): void
    {
        $approved = ApprovalRequest::where('action_type', 'LARGE_PAYOUT')
            ->where('target_type', 'PROTECTED_PAYMENT')
            ->where('target_identifier', (string) $payment->protected_payment_id)
            ->where('status', 'APPROVED')
            ->exists();

        if (! $approved) {
            throw new ConflictHttpException(
                'This payout exceeds the dual-control threshold. An administrator must create a LARGE_PAYOUT approval request and an independent checker must approve it before settlement (P-05 / FR-036).'
            );
        }
    }
}
