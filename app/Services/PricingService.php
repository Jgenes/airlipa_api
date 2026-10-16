<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\FeeQuote;
use App\Models\FeeRule;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PricingService
{
    /**
     * Ensure default price-book fee rules are seeded in the database (M15).
     */
    public function ensureDefaultFeeRulesExist(): void
    {
        if (FeeRule::count() > 0) {
            return;
        }

        $defaults = (array) config('protected_payment.default_fee_rules', []);

        foreach ($defaults as $rule) {
            FeeRule::firstOrCreate(
                ['fee_rule_id' => $rule['fee_rule_id']],
                array_merge($rule, ['status' => 'ACTIVE'])
            );
        }
    }

    /**
     * Calculate deterministic fee lines and create an immutable FeeQuote (SASDS 3.5.4, 3.5.5, FR-019).
     * Uses exact integer minor-unit arithmetic; never binary floating-point.
     *
     * @param array<string, mixed> $data
     */
    public function createQuote(User $initiator, array $data): FeeQuote
    {
        $this->ensureDefaultFeeRulesExist();

        $merchantId = (int) ($data['merchant_id'] ?? $initiator->id);
        $merchant = User::findOrFail($merchantId);

        if (!$merchant->isVerifiedForTrading()) {
            throw ValidationException::withMessages([
                'merchant_id' => ['The specified merchant/seller has not completed required KYC/KYB verification (BPA-SBV-02 / FR-003).'],
            ]);
        }

        $buyerId = isset($data['buyer_id']) ? (int) $data['buyer_id'] : null;
        if ($buyerId !== null && $buyerId === $merchant->id) {
            throw ValidationException::withMessages([
                'buyer_id' => ['Buyer and merchant cannot be the same party.'],
            ]);
        }

        $transactionType = isset($data['transaction_type'])
            ? TransactionType::from(strtoupper((string) $data['transaction_type']))
            : TransactionType::ProtectedPayment;

        $currency = strtoupper((string) ($data['currency'] ?? config('protected_payment.default_currency', 'TZS')));
        $principalMinor = (int) $data['principal_amount_minor'];

        if ($principalMinor <= 0) {
            throw ValidationException::withMessages([
                'principal_amount_minor' => ['Principal amount must be greater than zero minor units.'],
            ]);
        }

        $calculation = $this->calculateFeeBreakdown($principalMinor, $currency, $transactionType);

        $correlationId = (string) ($data['correlation_id'] ?? ('cor_' . Str::lower(Str::ulid())));
        $quoteId = 'fq_' . Str::lower(Str::ulid());
        $termsVersion = (string) config('protected_payment.terms_version', 'TZ-PP-TERMS-2026-10-v1');
        $expiryMinutes = (int) config('protected_payment.quote_expiry_minutes', 30);
        $expiresAt = now()->addMinutes($expiryMinutes);

        $disclosureHash = hash('sha256', json_encode([
            'pricing_version' => $calculation['pricing_version'],
            'transaction_type' => $transactionType->value,
            'currency' => $currency,
            'principal_amount_minor' => $principalMinor,
            'total_payable_minor' => $calculation['total_payable_minor'],
            'merchant_net_minor' => $calculation['merchant_net_minor'],
            'fee_lines' => $calculation['fee_lines'],
            'terms_version' => $termsVersion,
        ], JSON_THROW_ON_ERROR));

        $quote = FeeQuote::create([
            'quote_id' => $quoteId,
            'correlation_id' => $correlationId,
            'pricing_version' => $calculation['pricing_version'],
            'created_by_user_id' => $initiator->id,
            'merchant_id' => $merchant->id,
            'buyer_id' => $buyerId,
            'transaction_type' => $transactionType,
            'currency' => $currency,
            'principal_amount_minor' => $principalMinor,
            'buyer_fee_minor' => $calculation['buyer_fee_minor'],
            'buyer_tax_minor' => $calculation['buyer_tax_minor'],
            'merchant_fee_minor' => $calculation['merchant_fee_minor'],
            'merchant_tax_minor' => $calculation['merchant_tax_minor'],
            'total_payable_minor' => $calculation['total_payable_minor'],
            'merchant_net_minor' => $calculation['merchant_net_minor'],
            'fee_lines' => $calculation['fee_lines'],
            'approval_references' => $calculation['approval_references'],
            'terms_version' => $termsVersion,
            'disclosure_hash' => $disclosureHash,
            'status' => 'CREATED',
            'accepted_at' => null,
            'expires_at' => $expiresAt,
        ]);

        AuditLogService::log('fee_quote_created', $initiator, [
            'quote_id' => $quote->quote_id,
            'correlation_id' => $quote->correlation_id,
            'pricing_version' => $quote->pricing_version,
            'principal_amount_minor' => $quote->principal_amount_minor,
            'total_payable_minor' => $quote->total_payable_minor,
        ]);

        return $quote;
    }

    /**
     * Deterministically calculate fee lines using integer minor-unit arithmetic (SASDS 3.5.4.C).
     *
     * @return array{
     *   pricing_version: string,
     *   buyer_fee_minor: int,
     *   buyer_tax_minor: int,
     *   merchant_fee_minor: int,
     *   merchant_tax_minor: int,
     *   total_payable_minor: int,
     *   merchant_net_minor: int,
     *   fee_lines: array<int, array<string, mixed>>,
     *   approval_references: array<int, string>
     * }
     */
    public function calculateFeeBreakdown(
        int $principalMinor,
        string $currency,
        TransactionType $transactionType
    ): array {
        $this->ensureDefaultFeeRulesExist();

        $rules = FeeRule::where('transaction_type', $transactionType->value)
            ->where('currency', strtoupper($currency))
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->get();

        // Fail closed when no approved rule applies (SASDS 3.5.4.D)
        if ($rules->isEmpty()) {
            throw ValidationException::withMessages([
                'currency' => ["No approved active fee rules found for {$transactionType->value} in currency {$currency}."],
            ]);
        }

        $buyerFeeMinor = 0;
        $buyerTaxMinor = 0;
        $merchantFeeMinor = 0;
        $merchantTaxMinor = 0;
        $feeLines = [];
        $approvalRefs = [];
        $pricingVersion = (string) config('protected_payment.pricing_version', 'TZ-R1-2026-10-01');

        foreach ($rules as $rule) {
            $pricingVersion = $rule->pricing_version;

            // Integer minor-unit calculation: Flat + intdiv(Base * RateBps, 10000)
            $calculatedFee = (int) $rule->flat_amount_minor + intdiv($principalMinor * (int) $rule->rate_bps, 10000);
            $feeWithFloor = max((int) $rule->min_fee_minor, $calculatedFee);
            $finalFeeMinor = $rule->max_fee_minor !== null
                ? min((int) $rule->max_fee_minor, $feeWithFloor)
                : $feeWithFloor;

            $taxMinor = intdiv($finalFeeMinor * (int) $rule->tax_rate_bps, 10000);

            if ($rule->payer === 'BUYER') {
                $buyerFeeMinor += $finalFeeMinor;
                $buyerTaxMinor += $taxMinor;
            } else {
                $merchantFeeMinor += $finalFeeMinor;
                $merchantTaxMinor += $taxMinor;
            }

            $approvalRefs[] = $rule->approval_reference;

            $feeLines[] = [
                'fee_rule_id' => $rule->fee_rule_id,
                'fee_code' => $rule->fee_code,
                'fee_class' => $rule->fee_class,
                'payer' => $rule->payer,
                'beneficiary' => $rule->beneficiary,
                'amount_minor' => $finalFeeMinor,
                'tax_code' => $rule->tax_code,
                'tax_rate_bps' => (int) $rule->tax_rate_bps,
                'tax_amount_minor' => $taxMinor,
                'refund_policy' => $rule->refund_policy,
                'recognition_policy' => $rule->recognition_policy,
                'disclosure_en' => $rule->disclosure_en,
                'disclosure_sw' => $rule->disclosure_sw,
            ];
        }

        $totalPayableMinor = $principalMinor + $buyerFeeMinor + $buyerTaxMinor;
        $merchantNetMinor = max(0, $principalMinor - $merchantFeeMinor - $merchantTaxMinor);

        return [
            'pricing_version' => $pricingVersion,
            'buyer_fee_minor' => $buyerFeeMinor,
            'buyer_tax_minor' => $buyerTaxMinor,
            'merchant_fee_minor' => $merchantFeeMinor,
            'merchant_tax_minor' => $merchantTaxMinor,
            'total_payable_minor' => $totalPayableMinor,
            'merchant_net_minor' => $merchantNetMinor,
            'fee_lines' => $feeLines,
            'approval_references' => array_values(array_unique($approvalRefs)),
        ];
    }

    /**
     * Accept an immutable fee quote prior to transaction creation or funding (BPA-REV-03, FR-006).
     */
    public function acceptQuote(FeeQuote $quote, User $buyer): FeeQuote
    {
        if ($quote->isExpired()) {
            $quote->update(['status' => 'EXPIRED']);
            throw ValidationException::withMessages([
                'quote_id' => ['The fee quote has expired. Please request a fresh quote.'],
            ]);
        }

        if ($quote->merchant_id === $buyer->id) {
            throw ValidationException::withMessages([
                'quote_id' => ['A merchant cannot accept their own fee quote as the buyer.'],
            ]);
        }

        if ($quote->buyer_id !== null && $quote->buyer_id !== $buyer->id) {
            throw ValidationException::withMessages([
                'quote_id' => ['This fee quote is bound to a different buyer.'],
            ]);
        }

        if ($quote->status === 'ACCEPTED') {
            return $quote;
        }

        if ($quote->status !== 'CREATED') {
            throw ValidationException::withMessages([
                'quote_id' => ["Quote cannot be accepted in status '{$quote->status}'."],
            ]);
        }

        $quote->update([
            'buyer_id' => $buyer->id,
            'status' => 'ACCEPTED',
            'accepted_at' => now(),
        ]);

        AuditLogService::log('fee_quote_accepted', $buyer, [
            'quote_id' => $quote->quote_id,
            'correlation_id' => $quote->correlation_id,
            'disclosure_hash' => $quote->disclosure_hash,
        ]);

        return $quote->fresh();
    }

    /**
     * Calculate refundable fee and tax components using the original accepted quote version (BPA-REV-14, BPA-REV-15).
     *
     * @return array{
     *   refund_principal_minor: int,
     *   refund_buyer_fee_minor: int,
     *   refund_buyer_tax_minor: int,
     *   total_buyer_refund_minor: int
     * }
     */
    public function calculateRefundComponents(FeeQuote $quote, int $refundPrincipalMinor): array
    {
        $originalPrincipal = (int) $quote->principal_amount_minor;
        if ($originalPrincipal <= 0 || $refundPrincipalMinor <= 0) {
            return [
                'refund_principal_minor' => 0,
                'refund_buyer_fee_minor' => 0,
                'refund_buyer_tax_minor' => 0,
                'total_buyer_refund_minor' => 0,
            ];
        }

        // Exact proration from original quote version without silent deductions
        if ($refundPrincipalMinor >= $originalPrincipal) {
            $refundBuyerFee = (int) $quote->buyer_fee_minor;
            $refundBuyerTax = (int) $quote->buyer_tax_minor;
        } else {
            $refundBuyerFee = intdiv((int) $quote->buyer_fee_minor * $refundPrincipalMinor, $originalPrincipal);
            $refundBuyerTax = intdiv((int) $quote->buyer_tax_minor * $refundPrincipalMinor, $originalPrincipal);
        }

        return [
            'refund_principal_minor' => $refundPrincipalMinor,
            'refund_buyer_fee_minor' => $refundBuyerFee,
            'refund_buyer_tax_minor' => $refundBuyerTax,
            'total_buyer_refund_minor' => $refundPrincipalMinor + $refundBuyerFee + $refundBuyerTax,
        ];
    }
}
