<?php

namespace App\Services;

use App\Models\ProtectedPayment;

/**
 * Dynamic deposit QR generation (Module 4 / QR DEPOSIT).
 *
 * Emits a TanQR / EMVCo-compatible merchant-presented payload carrying the
 * EXACT payable amount and the contract/payment reference, pointed at the
 * single pooled Selcom escrow account (Lipa Namba). Any wallet or bank app
 * that speaks EMVCo QR (M-Pesa, Tigo Pesa, Mixx by Yas, Airtel Money, Halopesa,
 * bank apps) can scan and pay; the reference tags the incoming Selcom
 * collection so webhooks reconcile to the right contract.
 */
class QrDepositService
{
    /**
     * Build the deposit QR package for a Protected Payment agreement.
     *
     * @return array<string, mixed>
     */
    public function buildDepositQr(ProtectedPayment $payment): array
    {
        $selcom = (array) config('protected_payment.selcom', []);
        $accountRef = (string) ($selcom['lipa_namba'] ?? 'SELCOM-ESCROW-0001');
        $merchantName = (string) ($selcom['account_name'] ?? 'AIRLIPA ESCROW POOL');
        $reference = $payment->escrowContract?->reference ?? $payment->reference;

        $emvco = $this->buildEmvcoPayload(
            amountMinor: (int) $payment->total_payable_minor,
            reference: $reference,
            accountRef: $accountRef,
            merchantName: $merchantName
        );

        return [
            'qr_format' => 'TANQR_EMVCO',
            'emvco_payload' => $emvco,
            'crc16' => substr($emvco, -4),
            'lipa_namba' => $accountRef,
            'custody_account_reference' => (string) ($selcom['escrow_account_reference'] ?? 'SELCOM-POOLED-ESCROW-001'),
            'merchant_name' => $merchantName,
            'amount_minor' => (int) $payment->total_payable_minor,
            'principal_amount_minor' => (int) $payment->principal_amount_minor,
            'currency' => $payment->currency,
            'reference' => $reference,
            'protected_payment_id' => $payment->protected_payment_id,
            'contract_id' => $payment->escrowContract?->contract_id,
            'deep_link' => rtrim((string) config('protected_payment.canonical_link_domain'), '/')
                . '/pay/' . $payment->reference,
            'single_use' => true,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * EMVCo TLV merchant-presented payload (dynamic, exact amount) with a
     * CRC16-CCITT (0xFFFF init, poly 0x1021) checksum, TanQR DID root.
     */
    public function buildEmvcoPayload(int $amountMinor, string $reference, string $accountRef, string $merchantName): string
    {
        $merchantInfo = $this->tlv('00', 'tz.co.selcom.qr')
            . $this->tlv('01', $accountRef)
            . $this->tlv('02', substr($reference, 0, 25));

        $additional = $this->tlv('05', substr($reference, 0, 25));

        $body = $this->tlv('00', '01')                       // EMVCo version
            . $this->tlv('01', '12')                         // dynamic point of initiation
            . $this->tlv('26', $merchantInfo)                // Selcom/TanQR merchant account
            . $this->tlv('52', '5999')                       // MCC: other
            . $this->tlv('53', '834')                        // currency TZS
            . $this->tlv('54', $this->amountString($amountMinor))
            . $this->tlv('58', 'TZ')
            . $this->tlv('59', substr(strtoupper($merchantName), 0, 25))
            . $this->tlv('60', 'DAR ES SALAAM')
            . $this->tlv('62', $additional)
            . '6304';

        return $body . $this->crc16($body);
    }

    protected function tlv(string $tag, string $value): string
    {
        return $tag . sprintf('%02d', strlen($value)) . $value;
    }

    protected function amountString(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, '.', '');
    }

    /**
     * CRC16-CCITT (CCITT-FALSE): init 0xFFFF, polynomial 0x1021.
     */
    public function crc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(sprintf('%04X', $crc));
    }
}
