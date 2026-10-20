<?php

namespace App\Services\PaymentProviders;

use App\Models\PaymentAttempt;
use App\Models\PaymentProviderCapability;
use App\Models\ProtectedPayment;
use App\Services\QrDepositService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Selcom adapter - the SINGLE licensed payment partner for all collections
 * (deposits), payouts (releases) and refunds on the Airlipa escrow platform.
 *
 * All buyer rails (Airtel Money, M-Pesa, Tigo Pesa/Mixx by Yas, Halopesa,
 * Selcom Pay/wallet, bank transfer, Airtel Lipa, TanQR) are collected THROUGH
 * Selcom into ONE pooled escrow account; rail codes remain as channel
 * identifiers inside Selcom collection requests.
 *
 * Implements the provider-neutral PaymentProviderAdapterInterface so a second
 * licensed partner can be added later without touching orchestration.
 * Sandbox mode (config protected_payment.selcom.sandbox) is a deterministic
 * in-process mock used by local development and the test suite.
 */
class SelcomProvider implements PaymentProviderAdapterInterface
{
    public const PROVIDER_CODE = 'SELCOM_TZ';

    public function __construct(
        protected QrDepositService $qrDepositService
    ) {}

    /**
     * Initiate a collection into the pooled Selcom escrow account.
     * Supports USSD/STK push where the rail allows, otherwise instructions
     * with the unique order reference; always returns a dynamic TanQR payload.
     */
    public function initiateCollection(
        PaymentAttempt $attempt,
        PaymentProviderCapability $capability,
        array $options = []
    ): array {
        $reference = 'SLC-' . strtoupper(Str::random(12));
        $rail = strtoupper((string) ($options['rail'] ?? $capability->provider_code));

        if ($this->isSandbox()) {
            $simulateOutcome = strtoupper((string) ($options['simulate_outcome'] ?? 'PENDING'));

            [$rawStatus, $normalizedStatus] = match ($simulateOutcome) {
                'TIMEOUT', 'UNKNOWN' => ['SELCOM_GATEWAY_TIMEOUT_UNCERTAIN', 'UNKNOWN'],
                'FAILED', 'DECLINED' => ['SELCOM_USER_DECLINED_OR_INSUFFICIENT_FUNDS', 'FAILED'],
                default => ['SELCOM_STK_PUSH_SENT_PENDING_CONFIRMATION', 'PENDING'],
            };

            return [
                'provider_reference' => $reference,
                'raw_status' => $rawStatus,
                'normalized_status' => $normalizedStatus,
                'instructions_en' => $this->instructionsEn($rail, $reference, $attempt),
                'instructions_sw' => $this->instructionsSw($rail, $reference, $attempt),
                'metadata' => $this->collectionMetadata($attempt, $capability, $reference, $rail),
            ];
        }

        try {
            $response = Http::withToken((string) config('protected_payment.selcom.api_key'))
                ->timeout((int) config('protected_payment.selcom.timeout_seconds', 20))
                ->post($this->baseUrl() . '/v1/collections', [
                    'reference' => $reference,
                    'rail' => $rail,
                    'amount_minor' => (int) $attempt->amount_minor,
                    'currency' => $attempt->currency,
                    'payer_instrument' => $attempt->payer_instrument,
                    'destination_account' => (string) config('protected_payment.selcom.escrow_account_reference'),
                    'callback_url' => (string) config('protected_payment.selcom.webhook_url'),
                    'idempotency_key' => $attempt->idempotency_key,
                ]);

            if ($response->failed()) {
                Log::warning('selcom_collection_http_error', [
                    'attempt_id' => $attempt->attempt_id,
                    'status' => $response->status(),
                ]);

                return [
                    'provider_reference' => $reference,
                    'raw_status' => 'SELCOM_GATEWAY_UNREACHABLE',
                    'normalized_status' => 'UNKNOWN',
                    'instructions_en' => $this->instructionsEn($rail, $reference, $attempt),
                    'instructions_sw' => $this->instructionsSw($rail, $reference, $attempt),
                    'metadata' => $this->collectionMetadata($attempt, $capability, $reference, $rail),
                ];
            }

            $body = $response->json() ?? [];

            return [
                'provider_reference' => (string) ($body['transaction_id'] ?? $reference),
                'raw_status' => (string) ($body['status'] ?? 'PENDING'),
                'normalized_status' => 'PENDING',
                'instructions_en' => $this->instructionsEn($rail, $reference, $attempt),
                'instructions_sw' => $this->instructionsSw($rail, $reference, $attempt),
                'metadata' => $this->collectionMetadata($attempt, $capability, $reference, $rail) + ['response' => $body],
            ];
        } catch (\Throwable $exception) {
            Log::warning('selcom_collection_exception', [
                'attempt_id' => $attempt->attempt_id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'provider_reference' => $reference,
                'raw_status' => 'SELCOM_GATEWAY_UNREACHABLE',
                'normalized_status' => 'UNKNOWN',
                'instructions_en' => $this->instructionsEn($rail, $reference, $attempt),
                'instructions_sw' => $this->instructionsSw($rail, $reference, $attempt),
                'metadata' => $this->collectionMetadata($attempt, $capability, $reference, $rail),
            ];
        }
    }

    /**
     * Authoritative status check for timeout/retry recovery (SASDS 4.3.8).
     */
    public function queryStatus(PaymentAttempt $attempt, PaymentProviderCapability $capability): array
    {
        if ($this->isSandbox()) {
            return [
                'provider_reference' => (string) $attempt->provider_reference,
                'raw_status' => (string) ($attempt->raw_provider_status ?? 'SELCOM_PENDING'),
                'normalized_status' => (string) $attempt->normalized_status,
                'amount_minor' => (int) $attempt->amount_minor,
                'currency' => (string) $attempt->currency,
            ];
        }

        try {
            $response = Http::withToken((string) config('protected_payment.selcom.api_key'))
                ->timeout((int) config('protected_payment.selcom.timeout_seconds', 20))
                ->get($this->baseUrl() . '/v1/collections/' . rawurlencode((string) $attempt->provider_reference));

            $body = $response->successful() ? ($response->json() ?? []) : [];

            return [
                'provider_reference' => (string) ($body['transaction_id'] ?? $attempt->provider_reference),
                'raw_status' => (string) ($body['status'] ?? 'UNKNOWN'),
                'normalized_status' => strtoupper((string) ($body['normalized_status'] ?? 'UNKNOWN')),
                'amount_minor' => (int) ($body['amount_minor'] ?? $attempt->amount_minor),
                'currency' => (string) ($body['currency'] ?? $attempt->currency),
            ];
        } catch (\Throwable $exception) {
            Log::warning('selcom_query_exception', [
                'attempt_id' => $attempt->attempt_id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'provider_reference' => (string) $attempt->provider_reference,
                'raw_status' => 'SELCOM_GATEWAY_UNREACHABLE',
                'normalized_status' => 'UNKNOWN',
                'amount_minor' => (int) $attempt->amount_minor,
                'currency' => (string) $attempt->currency,
            ];
        }
    }

    /**
     * Disbursement from the pooled Selcom escrow account to the merchant.
     */
    public function executeRelease(
        ProtectedPayment $payment,
        PaymentProviderCapability $capability,
        int $amountMinor,
        string $idempotencyKey
    ): array {
        if ($this->isSandbox()) {
            return $this->sandboxDisbursement($payment, $amountMinor, $idempotencyKey, 'REL');
        }

        return $this->liveDisbursement($payment, $amountMinor, $idempotencyKey, '/v1/disbursements', 'REL');
    }

    /**
     * Refund from the pooled Selcom escrow account to the buyer.
     */
    public function executeRefund(
        ProtectedPayment $payment,
        PaymentProviderCapability $capability,
        int $amountMinor,
        string $idempotencyKey
    ): array {
        if ($this->isSandbox()) {
            return $this->sandboxDisbursement($payment, $amountMinor, $idempotencyKey, 'RFD');
        }

        return $this->liveDisbursement($payment, $amountMinor, $idempotencyKey, '/v1/refunds', 'RFD');
    }

    /**
     * Verify HMAC-SHA256 webhook signature and timestamp replay protection (FR-009, FR-026).
     */
    public function verifyWebhookSignature(
        PaymentProviderCapability $capability,
        string $rawPayload,
        string $signature,
        string $timestamp,
        string $nonce
    ): bool {
        if ($signature === '' || $timestamp === '' || $nonce === '') {
            return false;
        }

        if (!ctype_digit($timestamp)) {
            return false;
        }

        $window = (int) config('protected_payment.webhook_replay_window_seconds', 300);

        if (abs(now()->timestamp - (int) $timestamp) > $window) {
            return false;
        }

        $expected = $this->signWebhookPayload((string) $capability->webhook_secret, $rawPayload, $timestamp, $nonce);

        return hash_equals($expected, $signature);
    }

    /**
     * Compute HMAC-SHA256 signature over timestamp.nonce.rawPayload.
     */
    public function signWebhookPayload(string $webhookSecret, string $rawPayload, string $timestamp, string $nonce): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$nonce}.{$rawPayload}", $webhookSecret);
    }

    /**
     * @return array<string, mixed>
     */
    protected function collectionMetadata(PaymentAttempt $attempt, PaymentProviderCapability $capability, string $reference, string $rail): array
    {
        $payment = $attempt->paymentIntent?->protectedPayment;

        return [
            'provider_code' => self::PROVIDER_CODE,
            'rail' => $rail,
            'custody_account_reference' => (string) config('protected_payment.selcom.escrow_account_reference', $capability->custody_account_reference),
            'lipa_namba' => (string) config('protected_payment.selcom.lipa_namba'),
            'contract_reference' => $payment?->escrowContract?->reference ?? $payment?->reference,
            'tanqr_payload' => $payment
                ? $this->qrDepositService->buildEmvcoPayload(
                    (int) $attempt->amount_minor,
                    (string) ($payment->escrowContract?->reference ?? $payment->reference),
                    (string) config('protected_payment.selcom.lipa_namba', 'SELCOM-ESCROW-0001'),
                    (string) config('protected_payment.selcom.account_name', 'AIRLIPA ESCROW POOL')
                )
                : null,
            'sandbox' => $this->isSandbox(),
        ];
    }

    /**
     * @return array{executed: bool, provider_release_reference?: string, provider_refund_reference?: string, raw_status: string}
     */
    protected function sandboxDisbursement(ProtectedPayment $payment, int $amountMinor, string $idempotencyKey, string $prefix): array
    {
        $hashSuffix = strtoupper(substr(hash('sha256', $payment->protected_payment_id . '|' . $prefix . '|' . $idempotencyKey), 0, 12));
        $key = $prefix === 'REL' ? 'provider_release_reference' : 'provider_refund_reference';

        return [
            'executed' => true,
            $key => "{$prefix}-SELCOM_TZ-{$hashSuffix}",
            'raw_status' => 'SELCOM_DISBURSEMENT_SETTLED',
        ];
    }

    /**
     * @return array{executed: bool, provider_release_reference?: string, provider_refund_reference?: string, raw_status: string}
     */
    protected function liveDisbursement(ProtectedPayment $payment, int $amountMinor, string $idempotencyKey, string $path, string $prefix): array
    {
        $key = $prefix === 'REL' ? 'provider_release_reference' : 'provider_refund_reference';

        try {
            $response = Http::withToken((string) config('protected_payment.selcom.api_key'))
                ->timeout((int) config('protected_payment.selcom.timeout_seconds', 20))
                ->post($this->baseUrl() . $path, [
                    'source_account' => (string) config('protected_payment.selcom.escrow_account_reference'),
                    'amount_minor' => $amountMinor,
                    'currency' => $payment->currency,
                    'reference' => $payment->reference,
                    'idempotency_key' => $idempotencyKey,
                ]);

            $body = $response->json() ?? [];

            return [
                'executed' => $response->successful(),
                $key => (string) ($body['transaction_id'] ?? ('unknown-' . $idempotencyKey)),
                'raw_status' => (string) ($body['status'] ?? ($response->successful() ? 'SELCOM_DISBURSEMENT_ACCEPTED' : 'SELCOM_DISBURSEMENT_REJECTED')),
            ];
        } catch (\Throwable $exception) {
            Log::warning('selcom_disbursement_exception', [
                'protected_payment_id' => $payment->protected_payment_id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'executed' => false,
                $key => 'unknown-' . $idempotencyKey,
                'raw_status' => 'SELCOM_GATEWAY_UNREACHABLE',
            ];
        }
    }

    protected function instructionsEn(string $rail, string $reference, PaymentAttempt $attempt): string
    {
        $amount = number_format(((int) $attempt->amount_minor) / 100, 2);

        return sprintf(
            'Pay TZS %s into the Airlipa pooled escrow account via Selcom (%s). Lipa Namba %s, reference %s. Approve the STK/USSD push on your phone or scan the TanQR code. Never share your PIN.',
            $amount,
            $rail,
            (string) config('protected_payment.selcom.lipa_namba', 'SELCOM-ESCROW-0001'),
            $reference
        );
    }

    protected function instructionsSw(string $rail, string $reference, PaymentAttempt $attempt): string
    {
        $amount = number_format(((int) $attempt->amount_minor) / 100, 2);

        return sprintf(
            'Lipa TZS %s kwenye akaunti ya pamoja ya eskrow ya Airlipa kupitia Selcom (%s). Lipa Namba %s, kumbukumbu %s. Idhinisha ujumbe wa STK/USSD kwenye simu yako au skani msimbo wa TanQR. Usishiriki PIN yako.',
            $amount,
            $rail,
            (string) config('protected_payment.selcom.lipa_namba', 'SELCOM-ESCROW-0001'),
            $reference
        );
    }

    protected function isSandbox(): bool
    {
        return (bool) config('protected_payment.selcom.sandbox', true);
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('protected_payment.selcom.base_url', 'https://api.selcom.co.tz'), '/');
    }
}
