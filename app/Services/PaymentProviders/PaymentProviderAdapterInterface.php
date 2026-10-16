<?php

namespace App\Services\PaymentProviders;

use App\Models\PaymentAttempt;
use App\Models\PaymentProviderCapability;
use App\Models\ProtectedPayment;

interface PaymentProviderAdapterInterface
{
    /**
     * Initiate a payment collection instruction with the licensed provider.
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
    ): array;

    /**
     * Query the authoritative status of an existing payment attempt from the provider.
     *
     * @param PaymentAttempt $attempt
     * @param PaymentProviderCapability $capability
     * @return array{
     *   provider_reference: string,
     *   raw_status: string,
     *   normalized_status: string,
     *   amount_minor: int,
     *   currency: string
     * }
     */
    public function queryStatus(
        PaymentAttempt $attempt,
        PaymentProviderCapability $capability
    ): array;

    /**
     * Instruct the licensed custodian/provider to release protected funds to merchant payable.
     *
     * @return array{
     *   executed: bool,
     *   provider_release_reference: string,
     *   raw_status: string
     * }
     */
    public function executeRelease(
        ProtectedPayment $payment,
        PaymentProviderCapability $capability,
        int $amountMinor,
        string $idempotencyKey
    ): array;

    /**
     * Instruct the licensed custodian/provider to execute a customer refund.
     *
     * @return array{
     *   executed: bool,
     *   provider_refund_reference: string,
     *   raw_status: string
     * }
     */
    public function executeRefund(
        ProtectedPayment $payment,
        PaymentProviderCapability $capability,
        int $amountMinor,
        string $idempotencyKey
    ): array;

    /**
     * Verify the authenticity and replay window of an inbound provider callback.
     */
    public function verifyWebhookSignature(
        PaymentProviderCapability $capability,
        string $rawPayload,
        string $signature,
        string $timestamp,
        string $nonce
    ): bool;

    /**
     * Generate a canonical HMAC-SHA256 signature for provider webhook verification/testing.
     */
    public function signWebhookPayload(
        string $webhookSecret,
        string $rawPayload,
        string $timestamp,
        string $nonce
    ): string;
}
