<?php

namespace App\Services;

use App\Enums\PaymentIntentStatus;
use App\Enums\ProtectedPaymentStatus;
use App\Enums\SettlementStatus;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Models\ProtectedPayment;
use App\Models\ProviderWebhookEvent;
use App\Models\ReconciliationException;
use App\Models\User;
use App\Services\PaymentProviders\PaymentProviderAdapterInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentOrchestrationService
{
    public function __construct(
        protected ProviderRegistryService $providerRegistry,
        protected PaymentProviderAdapterInterface $providerAdapter,
        protected LedgerService $ledgerService
    ) {}

    /**
     * Create or resume an idempotent PaymentIntent and initiate a provider PaymentAttempt (FR-007, FR-008, BPA-SVC-06).
     *
     * @param array<string, mixed> $data
     * @return array{
     *   idempotent_replay: bool,
     *   intent: PaymentIntent,
     *   attempt: PaymentAttempt,
     *   provider_instructions: array<string, mixed>
     * }
     */
    public function initiatePayment(
        ProtectedPayment $payment,
        User $buyer,
        array $data,
        string $idempotencyKey
    ): array {
        if ($payment->buyer_id !== $buyer->id) {
            throw new AccessDeniedHttpException('Only the designated buyer of this Protected Payment agreement may initiate funding.');
        }

        $providerCode = strtoupper(trim((string) $data['provider_code']));
        $payerInstrument = isset($data['payer_phone_or_account']) ? trim((string) $data['payer_phone_or_account']) : $buyer->phone;
        $simulateOutcome = strtoupper((string) ($data['simulate_outcome'] ?? 'PENDING'));

        $requestHash = hash('sha256', json_encode([
            'protected_payment_id' => $payment->protected_payment_id,
            'provider_code' => $providerCode,
            'payer_instrument' => $payerInstrument,
            'amount_minor' => (int) $payment->total_payable_minor,
            'currency' => $payment->currency,
        ], JSON_THROW_ON_ERROR));

        // 1. Check client-scoped idempotency (FR-007, SASDS 4.2.8.4)
        $existingIntent = PaymentIntent::with('latestAttempt')
            ->where('payer_id', $buyer->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingIntent) {
            if (!hash_equals($existingIntent->request_hash, $requestHash)) {
                throw new HttpException(
                    409,
                    'Idempotency key conflict: the same Idempotency-Key was already used with a different payment payload (FR-007).'
                );
            }

            $latestAttempt = $existingIntent->latestAttempt;
            return [
                'idempotent_replay' => true,
                'intent' => $existingIntent,
                'attempt' => $latestAttempt,
                'provider_instructions' => $latestAttempt?->provider_payload ?? [],
            ];
        }

        // 2. Validate Protected Payment state
        if (!in_array($payment->status, [ProtectedPaymentStatus::AwaitingFunding, ProtectedPaymentStatus::PaymentProcessing], true)) {
            throw ValidationException::withMessages([
                'protected_payment_id' => ["Cannot initiate funding when Protected Payment is in state '{$payment->status->value}'."],
            ]);
        }

        // 3. Enforce BPA-SVC-06: Safe routing — never retry or failover while an earlier attempt is uncertain
        $uncertainAttempt = PaymentAttempt::whereHas('paymentIntent', function ($q) use ($payment) {
            $q->where('protected_payment_id', $payment->id);
        })->whereIn('normalized_status', ['SUBMITTED', 'PENDING', 'UNKNOWN'])->first();

        if ($uncertainAttempt) {
            throw new HttpException(
                409,
                "BPA-SVC-06 safe-failover block: An existing payment attempt ({$uncertainAttempt->attempt_id} via {$uncertainAttempt->provider_code}) has an uncertain status ({$uncertainAttempt->normalized_status}). Query authoritative status before initiating another payment."
            );
        }

        // 4. Validate provider capability in M18 registry (FR-008)
        $capability = $this->providerRegistry->resolveApprovedCapability(
            providerCode: $providerCode,
            currency: $payment->currency,
            amountMinor: (int) $payment->total_payable_minor,
            requireProtectedHold: true
        );

        return DB::transaction(function () use (
            $payment,
            $buyer,
            $capability,
            $payerInstrument,
            $idempotencyKey,
            $requestHash,
            $simulateOutcome
        ): array {
            $expiryMinutes = (int) config('protected_payment.intent_expiry_minutes', 30);

            $intent = PaymentIntent::create([
                'intent_id' => 'pi_' . Str::lower(Str::ulid()),
                'correlation_id' => $payment->correlation_id,
                'protected_payment_id' => $payment->id,
                'fee_quote_id' => $payment->fee_quote_id,
                'payer_id' => $buyer->id,
                'payee_id' => $payment->merchant_id,
                'currency' => $payment->currency,
                'amount_minor' => (int) $payment->total_payable_minor,
                'principal_amount_minor' => (int) $payment->principal_amount_minor,
                'buyer_fee_minor' => (int) $payment->buyer_fee_minor,
                'buyer_tax_minor' => (int) $payment->buyer_tax_minor,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => PaymentIntentStatus::Submitted,
                'expires_at' => now()->addMinutes($expiryMinutes),
            ]);

            $attempt = PaymentAttempt::create([
                'attempt_id' => 'pa_' . Str::lower(Str::ulid()),
                'correlation_id' => $payment->correlation_id,
                'payment_intent_id' => $intent->id,
                'provider_code' => $capability->provider_code,
                'payment_method' => $capability->provider_type,
                'payer_instrument' => $payerInstrument,
                'currency' => $payment->currency,
                'amount_minor' => (int) $payment->total_payable_minor,
                'idempotency_key' => $idempotencyKey,
                'normalized_status' => 'SUBMITTED',
                'request_hash' => $requestHash,
                'submitted_at' => now(),
            ]);

            // Invoke provider adapter
            $gatewayResult = $this->providerAdapter->initiateCollection($attempt, $capability, [
                'simulate_outcome' => $simulateOutcome,
            ]);

            $normalizedStatus = $gatewayResult['normalized_status'];

            $attempt->update([
                'provider_reference' => $gatewayResult['provider_reference'],
                'raw_provider_status' => $gatewayResult['raw_status'],
                'normalized_status' => $normalizedStatus,
                'failure_reason' => $normalizedStatus === 'FAILED' ? $gatewayResult['raw_status'] : null,
                'provider_payload' => $gatewayResult,
                'completed_at' => $normalizedStatus === 'FAILED' ? now() : null,
            ]);

            $intentStatus = match ($normalizedStatus) {
                'UNKNOWN' => PaymentIntentStatus::Unknown,
                'FAILED' => PaymentIntentStatus::Failed,
                default => PaymentIntentStatus::Pending,
            };

            $intent->update(['status' => $intentStatus]);

            $paymentStatus = match ($normalizedStatus) {
                'UNKNOWN' => ProtectedPaymentStatus::Unknown,
                'FAILED' => ProtectedPaymentStatus::AwaitingFunding,
                default => ProtectedPaymentStatus::PaymentProcessing,
            };

            $payment->update([
                'status' => $paymentStatus,
                'custody_provider_code' => $capability->provider_code,
                'state_version' => (int) $payment->state_version + 1,
            ]);

            AuditLogService::log('payment_attempt_submitted', $buyer, [
                'protected_payment_id' => $payment->protected_payment_id,
                'intent_id' => $intent->intent_id,
                'attempt_id' => $attempt->attempt_id,
                'provider_code' => $capability->provider_code,
                'provider_reference' => $attempt->provider_reference,
                'normalized_status' => $normalizedStatus,
                'correlation_id' => $payment->correlation_id,
            ]);

            return [
                'idempotent_replay' => false,
                'intent' => $intent->fresh(),
                'attempt' => $attempt->fresh(),
                'provider_instructions' => $gatewayResult,
            ];
        });
    }

    /**
     * Process an inbound provider callback/webhook with strict HMAC verification,
     * idempotency, amount/currency matching, and ledger posting (FR-009, FR-026, SASDS 4.2.7).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function processProviderWebhook(
        string $providerCode,
        array $payload,
        string $rawPayload,
        string $signature,
        string $timestamp,
        string $nonce
    ): array {
        $this->providerRegistry->ensureDefaultProvidersExist();

        $normalizedProviderCode = strtoupper(trim($providerCode));
        $capability = \App\Models\PaymentProviderCapability::where('provider_code', $normalizedProviderCode)->first();

        if (!$capability) {
            throw new AccessDeniedHttpException("Unknown payment provider '{$normalizedProviderCode}'.");
        }

        $messageHash = 'sha256:' . hash('sha256', $rawPayload);
        $isValidSignature = $this->providerAdapter->verifyWebhookSignature(
            $capability,
            $rawPayload,
            $signature,
            $timestamp,
            $nonce
        );

        if (!$isValidSignature) {
            AuditLogService::log('provider_webhook_rejected_signature', null, [
                'provider_code' => $normalizedProviderCode,
                'message_hash' => $messageHash,
            ]);

            throw new AccessDeniedHttpException('Invalid or expired provider webhook signature (FR-009 / FR-026).');
        }

        $eventId = (string) ($payload['event_id'] ?? ('evt_' . hash('sha256', $rawPayload)));
        $eventType = (string) ($payload['event_type'] ?? 'ProviderPaymentConfirmed');
        $providerTxnId = (string) ($payload['provider_transaction_id'] ?? ($payload['provider']['transaction_id'] ?? ''));
        $providerStatus = strtoupper((string) ($payload['status'] ?? ($payload['provider']['status'] ?? 'SUCCESS')));
        $currency = strtoupper((string) ($payload['currency'] ?? ($payload['money']['currency'] ?? 'TZS')));
        $amountMinor = (int) ($payload['amount_minor'] ?? ($payload['money']['amount_minor'] ?? 0));
        $attemptIdStr = (string) ($payload['attempt_id'] ?? '');
        $providerRefStr = (string) ($payload['provider_reference'] ?? $providerTxnId);

        // 1. Check idempotent inbox for duplicate event_id or already-confirmed provider_transaction_id (BPA-DEF-10)
        $existingEvent = ProviderWebhookEvent::where('event_id', $eventId)->first();
        if ($existingEvent && $existingEvent->processing_status === 'PROCESSED') {
            $intent = $existingEvent->paymentIntent;
            $payment = $intent?->protectedPayment;

            return [
                'duplicate_ignored' => true,
                'event_id' => $existingEvent->event_id,
                'processing_status' => 'DUPLICATE_IGNORED',
                'protected_payment_status' => $payment?->status?->value,
                'intent_status' => $intent?->status?->value,
            ];
        }

        // Resolve PaymentAttempt
        $attempt = null;
        if ($attemptIdStr !== '') {
            $attempt = PaymentAttempt::with('paymentIntent.protectedPayment.feeQuote')
                ->where('attempt_id', $attemptIdStr)
                ->first();
        }
        if (!$attempt && $providerRefStr !== '') {
            $attempt = PaymentAttempt::with('paymentIntent.protectedPayment.feeQuote')
                ->where('provider_reference', $providerRefStr)
                ->first();
        }

        if (!$attempt) {
            throw ValidationException::withMessages([
                'attempt_id' => ['No matching payment attempt found for this provider callback.'],
            ]);
        }

        $intent = $attempt->paymentIntent;
        $payment = $intent->protectedPayment;

        // If this attempt/intent already succeeded with the same amount & currency, return idempotently
        if (
            $intent->status === PaymentIntentStatus::Succeeded
            && $providerStatus === 'SUCCESS'
            && $amountMinor === (int) $intent->amount_minor
            && $currency === strtoupper($intent->currency)
        ) {
            ProviderWebhookEvent::firstOrCreate(
                ['event_id' => $eventId],
                [
                    'correlation_id' => $payment->correlation_id,
                    'provider_code' => $normalizedProviderCode,
                    'provider_transaction_id' => $providerTxnId ?: $providerRefStr,
                    'event_type' => $eventType,
                    'payment_attempt_id' => $attempt->id,
                    'payment_intent_id' => $intent->id,
                    'currency' => $currency,
                    'amount_minor' => $amountMinor,
                    'signature_key_id' => (string) ($payload['key_id'] ?? 'default'),
                    'message_hash' => $messageHash,
                    'signature_valid' => true,
                    'processing_status' => 'DUPLICATE_IGNORED',
                    'raw_payload' => $payload,
                    'occurred_at' => now(),
                    'processed_at' => now(),
                ]
            );

            return [
                'duplicate_ignored' => true,
                'event_id' => $eventId,
                'processing_status' => 'DUPLICATE_IGNORED',
                'protected_payment_status' => $payment->status->value,
                'intent_status' => $intent->status->value,
            ];
        }

        // 2. Validate amount & currency against accepted intent (FR-009 exception path: mismatch -> UNKNOWN)
        if ($amountMinor !== (int) $intent->amount_minor || $currency !== strtoupper($intent->currency)) {
            return DB::transaction(function () use (
                $eventId,
                $normalizedProviderCode,
                $providerTxnId,
                $providerRefStr,
                $eventType,
                $attempt,
                $intent,
                $payment,
                $currency,
                $amountMinor,
                $messageHash,
                $payload
            ): array {
                $attempt->update([
                    'raw_provider_status' => 'MISMATCH_DETECTED',
                    'normalized_status' => 'UNKNOWN',
                ]);

                $intent->update(['status' => PaymentIntentStatus::Unknown]);

                $payment->update([
                    'status' => ProtectedPaymentStatus::Unknown,
                    'settlement_status' => SettlementStatus::Blocked,
                    'state_version' => (int) $payment->state_version + 1,
                ]);

                $exception = ReconciliationException::create([
                    'exception_id' => 'rex_' . Str::lower(Str::ulid()),
                    'correlation_id' => $payment->correlation_id,
                    'protected_payment_id' => $payment->id,
                    'payment_intent_id' => $intent->id,
                    'provider_code' => $normalizedProviderCode,
                    'provider_reference' => $providerTxnId ?: $providerRefStr,
                    'category' => $currency !== strtoupper($intent->currency) ? 'CURRENCY_MISMATCH' : 'AMOUNT_MISMATCH',
                    'severity' => 'CRITICAL',
                    'status' => 'OPEN',
                    'details' => "Callback reported {$currency} {$amountMinor}, expected {$intent->currency} {$intent->amount_minor}. Release and settlement blocked.",
                    'metadata' => [
                        'expected_currency' => $intent->currency,
                        'expected_amount_minor' => (int) $intent->amount_minor,
                        'reported_currency' => $currency,
                        'reported_amount_minor' => $amountMinor,
                    ],
                    'sla_due_at' => now()->addHours(24),
                ]);

                ProviderWebhookEvent::updateOrCreate(
                    ['event_id' => $eventId],
                    [
                        'correlation_id' => $payment->correlation_id,
                        'provider_code' => $normalizedProviderCode,
                        'provider_transaction_id' => $providerTxnId ?: $providerRefStr,
                        'event_type' => $eventType,
                        'payment_attempt_id' => $attempt->id,
                        'payment_intent_id' => $intent->id,
                        'currency' => $currency,
                        'amount_minor' => $amountMinor,
                        'signature_key_id' => (string) ($payload['key_id'] ?? 'default'),
                        'message_hash' => $messageHash,
                        'signature_valid' => true,
                        'processing_status' => 'MISMATCH_BLOCKED',
                        'raw_payload' => $payload,
                        'occurred_at' => now(),
                        'processed_at' => now(),
                    ]
                );

                AuditLogService::log('provider_webhook_mismatch_blocked', null, [
                    'protected_payment_id' => $payment->protected_payment_id,
                    'intent_id' => $intent->intent_id,
                    'exception_id' => $exception->exception_id,
                    'correlation_id' => $payment->correlation_id,
                ]);

                return [
                    'duplicate_ignored' => false,
                    'event_id' => $eventId,
                    'processing_status' => 'MISMATCH_BLOCKED',
                    'protected_payment_status' => ProtectedPaymentStatus::Unknown->value,
                    'intent_status' => PaymentIntentStatus::Unknown->value,
                    'reconciliation_exception_id' => $exception->exception_id,
                ];
            });
        }

        // 3. Handle FAILED callback
        if (in_array($providerStatus, ['FAILED', 'DECLINED', 'CANCELLED'], true)) {
            return DB::transaction(function () use (
                $eventId,
                $normalizedProviderCode,
                $providerTxnId,
                $providerRefStr,
                $eventType,
                $attempt,
                $intent,
                $payment,
                $currency,
                $amountMinor,
                $messageHash,
                $payload,
                $providerStatus
            ): array {
                $attempt->update([
                    'raw_provider_status' => $providerStatus,
                    'normalized_status' => 'FAILED',
                    'failure_reason' => (string) ($payload['reason'] ?? 'Provider reported failure'),
                    'completed_at' => now(),
                ]);

                $intent->update(['status' => PaymentIntentStatus::Failed]);

                $payment->update([
                    'status' => ProtectedPaymentStatus::AwaitingFunding,
                    'state_version' => (int) $payment->state_version + 1,
                ]);

                ProviderWebhookEvent::updateOrCreate(
                    ['event_id' => $eventId],
                    [
                        'correlation_id' => $payment->correlation_id,
                        'provider_code' => $normalizedProviderCode,
                        'provider_transaction_id' => $providerTxnId ?: $providerRefStr,
                        'event_type' => $eventType,
                        'payment_attempt_id' => $attempt->id,
                        'payment_intent_id' => $intent->id,
                        'currency' => $currency,
                        'amount_minor' => $amountMinor,
                        'signature_key_id' => (string) ($payload['key_id'] ?? 'default'),
                        'message_hash' => $messageHash,
                        'signature_valid' => true,
                        'processing_status' => 'PROCESSED',
                        'raw_payload' => $payload,
                        'occurred_at' => now(),
                        'processed_at' => now(),
                    ]
                );

                return [
                    'duplicate_ignored' => false,
                    'event_id' => $eventId,
                    'processing_status' => 'PROCESSED',
                    'protected_payment_status' => $payment->status->value,
                    'intent_status' => PaymentIntentStatus::Failed->value,
                ];
            });
        }

        // 4. Handle SUCCEEDED funding confirmation (FR-009)
        return DB::transaction(function () use (
            $eventId,
            $normalizedProviderCode,
            $providerTxnId,
            $providerRefStr,
            $eventType,
            $attempt,
            $intent,
            $payment,
            $currency,
            $amountMinor,
            $messageHash,
            $payload
        ): array {
            $finalProviderRef = $providerTxnId !== '' ? $providerTxnId : ($attempt->provider_reference ?? $providerRefStr);

            $attempt->update([
                'provider_reference' => $finalProviderRef,
                'raw_provider_status' => 'SUCCESS',
                'normalized_status' => 'SUCCEEDED',
                'completed_at' => now(),
            ]);

            $intent->update([
                'status' => PaymentIntentStatus::Succeeded,
                'confirmed_at' => now(),
            ]);

            // Post balanced funding journal to M08 operational sub-ledger
            $journal = $this->ledgerService->postFundingConfirmed(
                payment: $payment,
                intent: $intent,
                providerReference: $finalProviderRef
            );

            // Transition ProtectedPayment to FUNDED_PROTECTED with exact conservation F = P = principal_amount_minor
            $principalMinor = (int) $payment->principal_amount_minor;

            $payment->update([
                'funded_amount_minor' => $principalMinor,
                'protected_balance_minor' => $principalMinor,
                'status' => ProtectedPaymentStatus::FundedProtected,
                'custody_provider_code' => $normalizedProviderCode,
                'custody_provider_reference' => $finalProviderRef,
                'funded_at' => now(),
                'state_version' => (int) $payment->state_version + 1,
            ]);

            if ($payment->feeQuote) {
                $payment->feeQuote->update(['status' => 'CONSUMED']);
            }

            if (!$payment->fresh()->verifyConservationInvariant()) {
                throw new \DomainException('Rule 36 conservation of value invariant failed after funding confirmation.');
            }

            ProviderWebhookEvent::updateOrCreate(
                ['event_id' => $eventId],
                [
                    'correlation_id' => $payment->correlation_id,
                    'provider_code' => $normalizedProviderCode,
                    'provider_transaction_id' => $finalProviderRef,
                    'event_type' => $eventType,
                    'payment_attempt_id' => $attempt->id,
                    'payment_intent_id' => $intent->id,
                    'currency' => $currency,
                    'amount_minor' => $amountMinor,
                    'signature_key_id' => (string) ($payload['key_id'] ?? 'default'),
                    'message_hash' => $messageHash,
                    'signature_valid' => true,
                    'processing_status' => 'PROCESSED',
                    'raw_payload' => $payload,
                    'occurred_at' => now(),
                    'processed_at' => now(),
                ]
            );

            AuditLogService::log('payment_funding_confirmed', null, [
                'protected_payment_id' => $payment->protected_payment_id,
                'reference' => $payment->reference,
                'intent_id' => $intent->intent_id,
                'journal_id' => $journal->journal_id,
                'provider_code' => $normalizedProviderCode,
                'provider_reference' => $finalProviderRef,
                'funded_amount_minor' => $principalMinor,
                'correlation_id' => $payment->correlation_id,
            ]);

            return [
                'duplicate_ignored' => false,
                'event_id' => $eventId,
                'processing_status' => 'PROCESSED',
                'protected_payment_status' => ProtectedPaymentStatus::FundedProtected->value,
                'intent_status' => PaymentIntentStatus::Succeeded->value,
                'journal_id' => $journal->journal_id,
            ];
        });
    }

    /**
     * Query the authoritative status of a payment intent/attempt for safe timeout recovery (SASDS 3.4.7, 4.3.8).
     *
     * @return array<string, mixed>
     */
    public function recoverAndCheckStatus(ProtectedPayment $payment): array
    {
        $payment->loadMissing(['latestPaymentIntent.latestAttempt']);
        $intent = $payment->latestPaymentIntent;
        $attempt = $intent?->latestAttempt;

        if (!$intent || !$attempt) {
            return [
                'recovery_state' => 'NOT_SUBMITTED',
                'protected_payment_status' => $payment->status->value,
                'customer_messages' => $payment->status->customerMessages(),
                'advice_en' => 'No payment attempt has been submitted yet. You may safely initiate payment.',
                'advice_sw' => 'Hakuna jaribio la malipo lililowasilishwa bado. Unaweza kuanzisha malipo kwa usalama.',
            ];
        }

        $recoveryState = match ($intent->status) {
            PaymentIntentStatus::Succeeded => 'CONFIRMED',
            PaymentIntentStatus::Failed => 'FAILED',
            PaymentIntentStatus::Unknown => 'UNKNOWN',
            default => 'ACCEPTED',
        };

        return [
            'recovery_state' => $recoveryState,
            'protected_payment_id' => $payment->protected_payment_id,
            'reference' => $payment->reference,
            'intent_id' => $intent->intent_id,
            'attempt_id' => $attempt->attempt_id,
            'provider_code' => $attempt->provider_code,
            'provider_reference' => $attempt->provider_reference,
            'intent_status' => $intent->status->value,
            'protected_payment_status' => $payment->status->value,
            'customer_messages' => $payment->status->customerMessages(),
            'do_not_repay' => in_array($recoveryState, ['ACCEPTED', 'UNKNOWN', 'CONFIRMED'], true),
        ];
    }
}
