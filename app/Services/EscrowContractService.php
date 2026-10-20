<?php

namespace App\Services;

use App\Enums\ContractSignerRole;
use App\Enums\ContractVersionStatus;
use App\Enums\EscrowContractStatus;
use App\Enums\OtpPurpose;
use App\Models\ContractShare;
use App\Models\ContractSignature;
use App\Models\EscrowContract;
use App\Models\EscrowContractVersion;
use App\Models\ProtectedPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Two-party escrow contract lifecycle (Phase 1):
 * DRAFT -> SHARED -> ACCEPTED (or REJECTED / EXPIRED / CANCELLED), with
 * counter-proposal versioning requiring both-party approval of final terms.
 *
 * On acceptance the contract mints the underlying Protected Payment agreement
 * (state machine per Figure 5 of the design pack) and opens the contract,
 * milestone and party subledgers atomically.
 */
class EscrowContractService
{
    public function __construct(
        protected ContractShareService $shareService,
        protected SubledgerService $subledgerService,
        protected OtpService $otpService,
        protected ProtectedPaymentService $protectedPaymentService,
        protected NotificationOutboxService $outboxService
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function createDraft(User $initiator, array $data): EscrowContract
    {
        return DB::transaction(function () use ($initiator, $data): EscrowContract {
            $terms = $this->canonicalTerms($data);

            $contract = EscrowContract::create([
                'contract_id' => 'esc_' . Str::lower(Str::ulid()),
                'reference' => 'ESC-' . strtoupper(Str::random(10)),
                'correlation_id' => (string) (Request::header('X-Correlation-Id') ?? ('corr_' . Str::lower(Str::random(24)))),
                'initiator_id' => $initiator->id,
                'initiator_role' => strtoupper((string) $data['initiator_role']),
                'counterparty_name' => trim((string) $data['counterparty_name']),
                'counterparty_phone' => $data['counterparty_phone'] ?? null,
                'counterparty_email' => $data['counterparty_email'] ?? null,
                'title' => trim((string) $data['title']),
                'description' => trim((string) $data['description']),
                'category' => $data['category'] ?? null,
                'currency' => strtoupper((string) ($data['currency'] ?? 'TZS')),
                'amount_minor' => (int) $data['amount_minor'],
                'milestones' => $data['milestones'] ?? null,
                'deliverables' => $data['deliverables'] ?? null,
                'attachments' => $data['attachments'] ?? null,
                'delivery_deadline_at' => $data['delivery_deadline_at'] ?? null,
                'inspection_hours' => (int) ($data['inspection_hours'] ?? config('escrow_contract.default_inspection_hours', 72)),
                'refund_terms' => $data['refund_terms'] ?? null,
                'dispute_terms' => $data['dispute_terms'] ?? null,
                'fee_payer' => strtoupper((string) ($data['fee_payer'] ?? 'BUYER')),
                'status' => EscrowContractStatus::Draft,
                'current_version' => 1,
            ]);

            EscrowContractVersion::create([
                'escrow_contract_id' => $contract->id,
                'version' => 1,
                'terms' => $terms,
                'terms_hash' => $this->termsHash($terms),
                'proposed_by' => $initiator->id,
                'status' => ContractVersionStatus::Current,
                'initiator_approved_at' => now(),
            ]);

            AuditLogService::log('escrow_contract_created', $initiator, [
                'contract_id' => $contract->contract_id,
                'amount_minor' => $contract->amount_minor,
            ]);

            return $contract;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateDraft(User $initiator, EscrowContract $contract, array $data): EscrowContract
    {
        $this->assertInitiator($contract, $initiator);

        if (!$contract->status->allowsEditing()) {
            throw new HttpException(422, 'Only DRAFT contracts can be edited. Use request-changes/amendments after sharing.');
        }

        return DB::transaction(function () use ($contract, $data): EscrowContract {
            $editable = [
                'counterparty_name', 'counterparty_phone', 'counterparty_email', 'title', 'description',
                'category', 'amount_minor', 'milestones', 'deliverables', 'attachments',
                'delivery_deadline_at', 'inspection_hours', 'refund_terms', 'dispute_terms', 'fee_payer',
            ];

            foreach ($editable as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $data[$field];
                    $contract->{$field} = in_array($field, ['amount_minor', 'inspection_hours'], true)
                        ? (int) $value
                        : (in_array($field, ['fee_payer'], true) ? strtoupper((string) $value) : $value);
                }
            }

            $contract->save();

            $version = $contract->versions()->where('version', $contract->current_version)->firstOrFail();
            $terms = $this->canonicalTerms($contract->toArray());
            $version->update([
                'terms' => $terms,
                'terms_hash' => $this->termsHash($terms),
            ]);

            AuditLogService::log('escrow_contract_draft_updated', $contract->initiator_id, ['contract_id' => $contract->contract_id]);

            return $contract->refresh();
        });
    }

    /**
     * Share the agreement through the requested channels and sign as initiator.
     *
     * @param array<int, string> $channels
     * @return array{contract: EscrowContract, shares: array<int, ContractShare>}
     */
    public function share(User $initiator, EscrowContract $contract, array $channels, ?string $message = null): array
    {
        $this->assertInitiator($contract, $initiator);

        if (!$contract->status->allowsSharing()) {
            throw new HttpException(422, 'A contract in status ' . $contract->status->value . ' cannot be shared.');
        }

        return DB::transaction(function () use ($initiator, $contract, $channels, $message): array {
            $version = $contract->versions()->where('version', $contract->current_version)->firstOrFail();

            ContractSignature::firstOrCreate(
                [
                    'escrow_contract_id' => $contract->id,
                    'user_id' => $initiator->id,
                    'signer_role' => ContractSignerRole::Initiator,
                ],
                [
                    'signature_id' => 'sig_' . Str::lower(Str::ulid()),
                    'escrow_contract_version_id' => $version->id,
                    'full_name' => $initiator->full_name ?? $initiator->name,
                    'phone' => (string) $initiator->phone,
                    'email' => $initiator->email,
                    'ip_address' => Request::ip(),
                    'user_agent' => substr((string) Request::userAgent(), 0, 500),
                    'contract_hash' => $version->terms_hash,
                    'terms_version' => 'v' . $version->version,
                    'signed_at' => now(),
                ]
            );

            $contract->status = EscrowContractStatus::Shared;
            $contract->acceptance_expires_at = now()->addHours((int) config('escrow_contract.default_acceptance_window_hours', 72));
            $contract->state_version = (int) $contract->state_version + 1;
            $contract->save();

            $shares = $this->shareService->createShares($contract, $channels, $message);

            AuditLogService::log('escrow_contract_shared', $initiator, [
                'contract_id' => $contract->contract_id,
                'channels' => $channels,
            ]);

            return ['contract' => $contract->refresh(), 'shares' => $shares];
        });
    }

    /**
     * Read-only public view payload for the counterparty (no account needed).
     *
     * @return array<string, mixed>
     */
    public function publicSummary(ContractShare $share): array
    {
        $contract = $share->contract;
        $version = $contract->versions()->where('version', $share->version)->firstOrFail();

        return [
            'contract_id' => $contract->contract_id,
            'reference' => $contract->reference,
            'title' => $contract->title,
            'description' => $contract->description,
            'category' => $contract->category,
            'currency' => $contract->currency,
            'amount_minor' => (int) $contract->amount_minor,
            'milestones' => $contract->milestones,
            'deliverables' => $contract->deliverables,
            'delivery_deadline_at' => $contract->delivery_deadline_at?->toIso8601String(),
            'inspection_hours' => (int) $contract->inspection_hours,
            'refund_terms' => $contract->refund_terms,
            'dispute_terms' => $contract->dispute_terms,
            'fee_payer' => $contract->fee_payer->value,
            'initiator_role' => $contract->initiator_role,
            'initiator_name' => $contract->initiator->full_name ?? $contract->initiator->name,
            'counterparty_name' => $contract->counterparty_name,
            'your_role' => $contract->initiator_role === 'BUYER' ? 'SELLER' : 'BUYER',
            'status' => $contract->status->value,
            'version' => (int) $share->version,
            'terms_hash' => $version->terms_hash,
            'acceptance_expires_at' => $contract->acceptance_expires_at?->toIso8601String(),
            'messages' => $contract->status->customerMessages(),
        ];
    }

    public function requestAcceptanceOtp(ContractShare $share, User $user): void
    {
        $this->assertCounterpartyIdentity($share->contract, $user);

        if (!$share->contract->isAcceptanceWindowOpen()) {
            throw new HttpException(422, 'The acceptance window for this contract is closed.');
        }

        $this->otpService->generateAndSend($user, OtpPurpose::ContractAcceptance);
    }

    /**
     * Counterparty acceptance with OTP-backed e-signature; mints the Protected
     * Payment and opens all subledgers in one atomic transaction.
     *
     * @param array<string, mixed> $data
     * @return array{contract: EscrowContract, payment: ProtectedPayment, handover_otp: string}
     */
    public function accept(ContractShare $share, User $user, array $data): array
    {
        $contract = $share->contract;
        $this->assertCounterpartyIdentity($contract, $user);

        if (!$contract->isAcceptanceWindowOpen()) {
            throw new HttpException(422, 'The acceptance window for this contract is closed.');
        }

        if ((int) $share->version !== (int) $contract->current_version) {
            throw new HttpException(422, 'This link refers to an outdated contract version. Request a fresh link.');
        }

        $otpResult = $this->otpService->verify($user, OtpPurpose::ContractAcceptance, (string) $data['otp_code']);

        if (!$otpResult['success']) {
            throw ValidationException::withMessages(['otp_code' => [$otpResult['message']]]);
        }

        return DB::transaction(function () use ($share, $contract, $user, $data): array {
            /** @var EscrowContract $locked */
            $locked = EscrowContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if (!$locked->status->allowsAcceptance()) {
                throw new HttpException(422, 'This contract is no longer open for acceptance (status ' . $locked->status->value . ').');
            }

            $version = $locked->versions()->where('version', $locked->current_version)->firstOrFail();

            ContractSignature::firstOrCreate(
                [
                    'escrow_contract_id' => $locked->id,
                    'user_id' => $user->id,
                    'signer_role' => ContractSignerRole::Counterparty,
                ],
                [
                    'signature_id' => 'sig_' . Str::lower(Str::ulid()),
                    'escrow_contract_version_id' => $version->id,
                    'full_name' => trim((string) ($data['full_name'] ?? $user->full_name ?? $user->name)),
                    'phone' => (string) $user->phone,
                    'email' => $user->email,
                    'ip_address' => Request::ip(),
                    'user_agent' => substr((string) Request::userAgent(), 0, 500),
                    'contract_hash' => $version->terms_hash,
                    'terms_version' => 'v' . $version->version,
                    'otp_verified_at' => now(),
                    'signed_at' => now(),
                ]
            );

            $initiator = $locked->initiator;
            $initiatorIsSeller = $locked->initiator_role === 'SELLER';

            $result = $this->protectedPaymentService->createDirectAgreement($initiator, [
                'merchant_id' => $initiatorIsSeller ? $initiator->id : $user->id,
                'buyer_id' => $initiatorIsSeller ? $user->id : $initiator->id,
                'title' => $locked->title,
                'item_description' => $locked->description,
                'currency' => $locked->currency,
                'principal_amount_minor' => (int) $locked->amount_minor,
                'milestones' => $this->milestonePayload($locked),
                'delivery_days' => $this->deliveryDays($locked),
                'return_policy' => $locked->refund_terms,
            ]);

            /** @var ProtectedPayment $payment */
            $payment = $result['agreement'];
            $payment->update(['escrow_contract_id' => $locked->id]);

            $locked->status = EscrowContractStatus::Accepted;
            $locked->counterparty_user_id = $user->id;
            $locked->accepted_at = now();
            $locked->state_version = (int) $locked->state_version + 1;
            $locked->save();

            $this->subledgerService->openForContract($locked->refresh(), $payment->refresh());

            $this->outboxService->publishEvent(
                'escrow_contract',
                $locked->contract_id,
                'contract.accepted',
                ['contract_id' => $locked->contract_id, 'protected_payment_id' => $payment->protected_payment_id],
                $initiator,
                'Contract accepted',
                sprintf('%s accepted contract %s. Deposit instructions are now active.', $user->full_name ?? $user->name, $locked->reference),
                'Mkataba umekubaliwa',
                sprintf('%s amekubali mkataba %s. Maelekezo ya amana yamewashwa.', $user->full_name ?? $user->name, $locked->reference)
            );

            AuditLogService::log('escrow_contract_accepted', $user, [
                'contract_id' => $locked->contract_id,
                'terms_hash' => $version->terms_hash,
                'protected_payment_id' => $payment->protected_payment_id,
            ]);

            return [
                'contract' => $locked,
                'payment' => $payment,
                'handover_otp' => (string) $result['handover_otp'],
            ];
        });
    }

    public function reject(ContractShare $share, User $user, ?string $reason): EscrowContract
    {
        $contract = $share->contract;
        $this->assertCounterpartyIdentity($contract, $user);

        if (!$contract->status->allowsAcceptance()) {
            throw new HttpException(422, 'Only SHARED contracts can be rejected.');
        }

        $contract->status = EscrowContractStatus::Rejected;
        $contract->rejected_at = now();
        $contract->rejection_reason = $reason;
        $contract->state_version = (int) $contract->state_version + 1;
        $contract->save();

        $this->outboxService->publishEvent(
            'escrow_contract',
            $contract->contract_id,
            'contract.rejected',
            ['contract_id' => $contract->contract_id, 'reason' => $reason],
            $contract->initiator,
            'Contract rejected',
            sprintf('%s rejected contract %s.', $user->full_name ?? $user->name, $contract->reference),
            'Mkataba umekataliwa',
            sprintf('%s amekataa mkataba %s.', $user->full_name ?? $user->name, $contract->reference)
        );

        AuditLogService::log('escrow_contract_rejected', $user, ['contract_id' => $contract->contract_id, 'reason' => $reason]);

        return $contract;
    }

    /**
     * Counter-proposal: a new version pending initiator approval. The contract
     * returns to DRAFT until the initiator approves, then is re-shared.
     *
     * @param array<string, mixed> $terms
     */
    public function requestChanges(ContractShare $share, User $user, array $terms): EscrowContractVersion
    {
        $contract = $share->contract;
        $this->assertCounterpartyIdentity($contract, $user);

        if (!$contract->status->allowsAcceptance()) {
            throw new HttpException(422, 'Changes can only be requested on SHARED contracts.');
        }

        return DB::transaction(function () use ($contract, $user, $terms): EscrowContractVersion {
            $nextVersion = (int) $contract->versions()->max('version') + 1;
            $merged = array_merge($contract->toArray(), $terms);
            $canonical = $this->canonicalTerms($merged);

            $version = EscrowContractVersion::create([
                'escrow_contract_id' => $contract->id,
                'version' => $nextVersion,
                'terms' => $canonical,
                'terms_hash' => $this->termsHash($canonical),
                'proposed_by' => $user->id,
                'status' => ContractVersionStatus::PendingInitiatorApproval,
                'counterparty_approved_at' => now(),
            ]);

            $contract->status = EscrowContractStatus::Draft;
            $contract->state_version = (int) $contract->state_version + 1;
            $contract->save();

            $this->outboxService->publishEvent(
                'escrow_contract',
                $contract->contract_id,
                'contract.changes_requested',
                ['contract_id' => $contract->contract_id, 'version' => $nextVersion],
                $contract->initiator,
                'Changes requested',
                sprintf('%s proposed changes (v%d) on contract %s.', $user->full_name ?? $user->name, $nextVersion, $contract->reference),
                'Mabadiliko yameombwa',
                sprintf('%s amependekeza mabadiliko (v%d) kwenye mkataba %s.', $user->full_name ?? $user->name, $nextVersion, $contract->reference)
            );

            AuditLogService::log('escrow_contract_changes_requested', $user, [
                'contract_id' => $contract->contract_id,
                'version' => $nextVersion,
            ]);

            return $version;
        });
    }

    /**
     * Initiator approves a counter-proposal: version becomes CURRENT, previous
     * versions are superseded and the contract is shareable/acceptable again.
     */
    public function approveVersion(User $initiator, EscrowContract $contract, int $versionNumber): EscrowContract
    {
        $this->assertInitiator($contract, $initiator);

        return DB::transaction(function () use ($contract, $versionNumber): EscrowContract {
            $version = $contract->versions()->where('version', $versionNumber)->firstOrFail();

            if ($version->status !== ContractVersionStatus::PendingInitiatorApproval) {
                throw new HttpException(422, 'Version ' . $versionNumber . ' is not awaiting initiator approval.');
            }

            $contract->versions()
                ->where('status', ContractVersionStatus::Current)
                ->update(['status' => ContractVersionStatus::Superseded]);

            $version->update([
                'status' => ContractVersionStatus::Current,
                'initiator_approved_at' => now(),
            ]);

            $contract->current_version = $versionNumber;
            $contract->status = EscrowContractStatus::Shared;
            $contract->acceptance_expires_at = now()->addHours((int) config('escrow_contract.default_acceptance_window_hours', 72));
            $contract->state_version = (int) $contract->state_version + 1;
            $contract->save();

            AuditLogService::log('escrow_contract_version_approved', $contract->initiator_id, [
                'contract_id' => $contract->contract_id,
                'version' => $versionNumber,
            ]);

            return $contract->refresh();
        });
    }

    public function cancel(User $initiator, EscrowContract $contract, ?string $reason): EscrowContract
    {
        $this->assertInitiator($contract, $initiator);

        if (!$contract->status->allowsCancellation()) {
            throw new HttpException(422, 'A contract in status ' . $contract->status->value . ' cannot be cancelled.');
        }

        $contract->status = EscrowContractStatus::Cancelled;
        $contract->cancelled_at = now();
        $contract->cancellation_reason = $reason;
        $contract->state_version = (int) $contract->state_version + 1;
        $contract->save();

        AuditLogService::log('escrow_contract_cancelled', $initiator, ['contract_id' => $contract->contract_id, 'reason' => $reason]);

        return $contract;
    }

    /**
     * Auto-expiry job body: SHARED contracts past their acceptance window.
     */
    public function expireOverdue(): int
    {
        $expired = 0;

        EscrowContract::where('status', EscrowContractStatus::Shared)
            ->whereNotNull('acceptance_expires_at')
            ->where('acceptance_expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (EscrowContract $contract) use (&$expired): void {
                $contract->status = EscrowContractStatus::Expired;
                $contract->expired_at = now();
                $contract->state_version = (int) $contract->state_version + 1;
                $contract->save();

                AuditLogService::log('escrow_contract_expired', null, ['contract_id' => $contract->contract_id]);
                $expired++;
            });

        return $expired;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function canonicalTerms(array $data): array
    {
        $milestones = array_map(static function (array $milestone): array {
            return [
                'title' => trim((string) ($milestone['title'] ?? '')),
                'description' => trim((string) ($milestone['description'] ?? '')),
                'amount_minor' => (int) ($milestone['amount_minor'] ?? 0),
            ];
        }, $data['milestones'] ?? []);

        return [
            'title' => trim((string) ($data['title'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'category' => $data['category'] ?? null,
            'currency' => strtoupper((string) ($data['currency'] ?? 'TZS')),
            'amount_minor' => (int) ($data['amount_minor'] ?? 0),
            'initiator_role' => strtoupper((string) ($data['initiator_role'] ?? 'BUYER')),
            'counterparty_name' => trim((string) ($data['counterparty_name'] ?? '')),
            'counterparty_phone' => $data['counterparty_phone'] ?? null,
            'counterparty_email' => $data['counterparty_email'] ?? null,
            'milestones' => $milestones,
            'deliverables' => $data['deliverables'] ?? null,
            'delivery_deadline_at' => $data['delivery_deadline_at'] ?? null,
            'inspection_hours' => (int) ($data['inspection_hours'] ?? config('escrow_contract.default_inspection_hours', 72)),
            'refund_terms' => $data['refund_terms'] ?? null,
            'dispute_terms' => $data['dispute_terms'] ?? null,
            'fee_payer' => strtoupper((string) ($data['fee_payer'] ?? 'BUYER')),
            'terms_version' => (string) config('protected_payment.terms_version', 'TZ-PP-TERMS-2026-10-v1'),
        ];
    }

    /**
     * SHA-256 tamper-evidence hash over the canonical final terms.
     *
     * @param array<string, mixed> $terms
     */
    public function termsHash(array $terms): string
    {
        ksort($terms);

        return hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR));
    }

    protected function assertInitiator(EscrowContract $contract, User $user): void
    {
        if ((int) $contract->initiator_id !== (int) $user->id) {
            throw new AccessDeniedHttpException('Only the contract initiator can perform this action.');
        }
    }

    protected function assertCounterpartyIdentity(EscrowContract $contract, User $user): void
    {
        if ($contract->counterparty_user_id && (int) $contract->counterparty_user_id === (int) $user->id) {
            return;
        }

        $phoneDigits = preg_replace('/\D+/', '', (string) $user->phone);
        $contractDigits = preg_replace('/\D+/', '', (string) $contract->counterparty_phone);

        if ($contractDigits && $phoneDigits && (str_ends_with($phoneDigits, substr($contractDigits, -9)) || str_ends_with($contractDigits, substr($phoneDigits, -9)))) {
            return;
        }

        if ($contract->counterparty_email && $user->email && strcasecmp((string) $contract->counterparty_email, (string) $user->email) === 0) {
            return;
        }

        throw new AccessDeniedHttpException('This contract was issued to a different counterparty.');
    }

    /**
     * @return array<int, array{title: string, description: string|null, amount_minor: int}>
     */
    protected function milestonePayload(EscrowContract $contract): array
    {
        return array_map(static function (array $milestone): array {
            return [
                'title' => (string) ($milestone['title'] ?? 'Milestone'),
                'description' => isset($milestone['description']) ? (string) $milestone['description'] : null,
                'amount_minor' => (int) ($milestone['amount_minor'] ?? 0),
            ];
        }, $contract->milestones ?? []);
    }

    protected function deliveryDays(EscrowContract $contract): int
    {
        if (!$contract->delivery_deadline_at) {
            return 3;
        }

        return max(1, (int) now()->diffInDays($contract->delivery_deadline_at, false));
    }
}
