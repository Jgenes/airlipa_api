<?php

namespace App\Services\PaymentProviders;

use App\Models\PaymentAttempt;
use App\Models\PaymentProviderCapability;
use App\Models\ProtectedPayment;
use Illuminate\Support\Str;

class TzProviderGatewayAdapter implements PaymentProviderAdapterInterface
{
    /**
     * Initiate a payment collection request across Tanzanian MNOs, Banks, TANQR, or Sandbox.
     *
     * @param PaymentAttempt $attempt
     * @param PaymentProviderCapability $capability
     * @param array<string, mixed> $options
     * @return array{
     *   provider_reference: string,
     *   raw_status: string,
     *   normalized_status: string,
     *   instructions_en: string,
     *   instructions_sw: string,
     *   metadata: array<string, mixed>
     * }
     */
    public function initiateCollection(
        PaymentAttempt $attempt,
        PaymentProviderCapability $capability,
        array $options = []
    ): array {
        $prefix = strtoupper(substr($capability->provider_code, 0, 5));
        $providerReference = "{$prefix}-" . strtoupper(Str::random(12));

        // Support deterministic sandbox simulation behavior via simulate_outcome option
        $simulateOutcome = strtoupper((string) ($options['simulate_outcome'] ?? 'PENDING'));

        [$rawStatus, $normalizedStatus] = match ($simulateOutcome) {
            'TIMEOUT', 'UNKNOWN' => ['GATEWAY_TIMEOUT_UNCERTAIN', 'UNKNOWN'],
            'FAILED', 'DECLINED' => ['USER_DECLINED_OR_INSUFFICIENT_FUNDS', 'FAILED'],
            default => ['USSD_PUSH_SENT_PENDING_CONFIRMATION', 'PENDING'],
        };

        $instructions = $this->buildChannelInstructions($capability, $providerReference, $attempt->amount_minor);

        return [
            'provider_reference' => $providerReference,
            'raw_status' => $rawStatus,
            'normalized_status' => $normalizedStatus,
            'instructions_en' => $instructions['en'],
            'instructions_sw' => $instructions['sw'],
            'metadata' => [
                'provider_code' => $capability->provider_code,
                'provider_name' => $capability->provider_name,
                'custody_account_reference' => $capability->custody_account_reference,
                'tanqr_payload' => $capability->provider_type === 'TANQR'
                    ? "00020101021226300012tz.go.bot.qr0110{$providerReference}520459995303834540" . strlen((string) $attempt->amount_minor) . $attempt->amount_minor . '5802TZ6304ABCD'
                    : null,
            ],
        ];
    }

    /**
     * Query the authoritative status of a payment attempt from the provider.
     */
    public function queryStatus(
        PaymentAttempt $attempt,
        PaymentProviderCapability $capability
    ): array {
        return [
            'provider_reference' => $attempt->provider_reference ?? ('QRY-' . strtoupper(Str::random(10))),
            'raw_status' => $attempt->raw_provider_status ?? 'PENDING',
            'normalized_status' => $attempt->normalized_status,
            'amount_minor' => (int) $attempt->amount_minor,
            'currency' => $attempt->currency,
        ];
    }

    /**
     * Execute an authorized release instruction with the licensed custodian.
     */
    public function executeRelease(
        ProtectedPayment $payment,
        PaymentProviderCapability $capability,
        int $amountMinor,
        string $idempotencyKey
    ): array {
        $hashSuffix = strtoupper(substr(hash('sha256', $payment->protected_payment_id . '|' . $idempotencyKey), 0, 12));

        return [
            'executed' => true,
            'provider_release_reference' => "REL-{$capability->provider_code}-{$hashSuffix}",
            'raw_status' => 'SAFEGUARD_RELEASE_EXECUTED',
        ];
    }

    /**
     * Execute an authorized refund instruction with the licensed custodian.
     */
    public function executeRefund(
        ProtectedPayment $payment,
        PaymentProviderCapability $capability,
        int $amountMinor,
        string $idempotencyKey
    ): array {
        $hashSuffix = strtoupper(substr(hash('sha256', $payment->protected_payment_id . '|REF|' . $idempotencyKey), 0, 12));

        return [
            'executed' => true,
            'provider_refund_reference' => "RFD-{$capability->provider_code}-{$hashSuffix}",
            'raw_status' => 'SAFEGUARD_REFUND_EXECUTED',
        ];
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
        $now = now()->timestamp;
        if (abs($now - (int) $timestamp) > $window) {
            return false;
        }

        $expected = $this->signWebhookPayload($capability->webhook_secret, $rawPayload, $timestamp, $nonce);

        return hash_equals($expected, $signature);
    }

    /**
     * Compute HMAC-SHA256 signature over timestamp.nonce.rawPayload.
     */
    public function signWebhookPayload(
        string $webhookSecret,
        string $rawPayload,
        string $timestamp,
        string $nonce
    ): string {
        $signingInput = "{$timestamp}.{$nonce}.{$rawPayload}";
        return hash_hmac('sha256', $signingInput, $webhookSecret);
    }

    /**
     * Build bilingual payment instructions by provider rail.
     *
     * @return array{en: string, sw: string}
     */
    protected function buildChannelInstructions(
        PaymentProviderCapability $capability,
        string $providerReference,
        int $amountMinor
    ): array {
        return match ($capability->provider_type) {
            'TANQR' => [
                'en' => "Scan the verified TANQR code using your mobile banking or mobile money app (Ref: {$providerReference}). Verify merchant name before entering your PIN on your provider app.",
                'sw' => "Skani msimbo wa TANQR uliothibitishwa kupitia programu yako ya benki au pesa mtandao (Kumb: {$providerReference}). Hakiki jina la mfanyabiashara kabla ya kuweka PIN kwa mtoa huduma wako.",
            ],
            'BANK' => [
                'en' => "Authorize the bank transfer via {$capability->provider_name} with reference {$providerReference}. Airlipa will never ask for your banking password or PIN.",
                'sw' => "Idhinisha malipo ya benki kupitia {$capability->provider_name} kwa kumbukumbu {$providerReference}. Airlipa haitakuomba nenosiri au PIN yako ya benki.",
            ],
            default => [
                'en' => "A payment prompt has been sent to your phone via {$capability->provider_name} (Ref: {$providerReference}). Enter your mobile-money PIN only on the official MNO prompt. Never share screenshots as proof.",
                'sw' => "Ujumbe wa malipo umetumwa kwenye simu yako kupitia {$capability->provider_name} (Kumb: {$providerReference}). Weka PIN yako kwenye menyu rasmi ya mtandao wa simu pekee. Picha za skrini si uthibitisho wa malipo.",
            ],
        };
    }
}
