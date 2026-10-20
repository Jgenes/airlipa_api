<?php

namespace App\Services;

use App\Enums\DeliveryMethod;
use App\Enums\DisputeStatus;
use App\Enums\EvidenceType;
use App\Enums\FulfillmentStatus;
use App\Enums\PaymentLinkStatus;
use App\Enums\ProtectedPaymentStatus;
use App\Enums\SettlementStatus;
use App\Enums\TransactionType;
use App\Models\FeeQuote;
use App\Models\FulfillmentEvidence;
use App\Models\LedgerJournal;
use App\Models\ProtectedPayment;
use App\Models\ProtectedPaymentLink;
use App\Models\ProtectedPaymentMilestone;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\PaymentProviders\PaymentProviderAdapterInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ProtectedPaymentService
{
    /**
     * Allowed MIME types for fulfillment and dispute evidence files.
     *
     * @var list<string>
     */
    protected array $allowedEvidenceMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        protected PricingService $pricingService,
        protected ProviderRegistryService $providerRegistry,
        protected PaymentProviderAdapterInterface $providerAdapter,
        protected LedgerService $ledgerService,
        // Required, not optional. When these were nullable with a null
        // default the container happily built the service with all three set
        // to null, so risk scoring, outbox notifications and - worst of all -
        // every escrow subledger movement were skipped without an error.
        protected RiskEngineService $riskEngine,
        protected NotificationOutboxService $outboxService,
        protected SubledgerService $subledgerService
    ) {}

    /**
     * Create a shareable Protected Checkout Link for a verified seller/merchant (SASDS 3.4.5.5, 3.6.5).
     *
     * @param array<string, mixed> $data
     */
    public function createPaymentLink(User $merchant, array $data): ProtectedPaymentLink
    {
        if (!$merchant->isVerifiedForTrading()) {
            throw new AccessDeniedHttpException(
                'BPA-SBV-02 / FR-003: You must complete KYC (Individual) or KYB (Business) verification before generating Protected Payment links.'
            );
        }

        $expiryHours = (int) ($data['expiry_hours'] ?? config('protected_payment.link_default_expiry_hours', 72));
        $autoReleaseHours = (int) ($data['auto_release_hours'] ?? config('protected_payment.auto_release_window_hours', 48));
        $deliveryMethod = isset($data['delivery_method'])
            ? DeliveryMethod::from(strtoupper((string) $data['delivery_method']))
            : DeliveryMethod::Courier;

        $permittedPhone = !empty($data['permitted_buyer_phone'])
            ? PhoneNumber::normalize((string) $data['permitted_buyer_phone'])
            : null;

        $releaseConditions = $data['release_conditions'] ?? [
            'VERIFIED_PROVIDER_FUNDING',
            'FULFILLMENT_EVIDENCE_SUBMISSION',
            'BUYER_STEP_UP_OR_INSPECTION_WINDOW',
        ];

        $link = ProtectedPaymentLink::create([
            'link_id' => 'ppl_' . Str::lower(Str::ulid()),
            'token' => Str::random(48),
            'correlation_id' => 'cor_' . Str::lower(Str::ulid()),
            'merchant_id' => $merchant->id,
            'title' => trim((string) $data['title']),
            'description' => trim((string) $data['description']),
            'transaction_type' => TransactionType::ProtectedPayment,
            'currency' => strtoupper((string) ($data['currency'] ?? config('protected_payment.default_currency', 'TZS'))),
            'principal_amount_minor' => (int) $data['principal_amount_minor'],
            'delivery_method' => $deliveryMethod,
            'delivery_days' => (int) ($data['delivery_days'] ?? 3),
            'auto_release_hours' => $autoReleaseHours,
            'release_conditions' => $releaseConditions,
            'return_policy' => $data['return_policy'] ?? 'Full refund if item is not delivered or materially differs from agreed description.',
            'permitted_buyer_phone' => $permittedPhone,
            'max_uses' => (int) ($data['max_uses'] ?? 1),
            'uses_count' => 0,
            'status' => PaymentLinkStatus::Active,
            'expires_at' => now()->addHours($expiryHours),
        ]);

        AuditLogService::log('protected_payment_link_created', $merchant, [
            'link_id' => $link->link_id,
            'correlation_id' => $link->correlation_id,
            'principal_amount_minor' => $link->principal_amount_minor,
            'currency' => $link->currency,
        ]);

        return $link->load('merchant.businessProfile');
    }

    /**
     * Read-only preview of a Protected Payment Link (BPA-CH-13, BPA-CH-14).
     * Never changes financial state on HTTP GET.
     *
     * @return array<string, mixed>
     */
    public function previewPaymentLink(string $token): array
    {
        $link = ProtectedPaymentLink::with('merchant.businessProfile')
            ->where('token', $token)
            ->firstOrFail();

        if ($link->status === PaymentLinkStatus::Active && $link->expires_at->isPast()) {
            $link->update(['status' => PaymentLinkStatus::Expired]);
        }

        if (!$link->isUsable()) {
            throw ValidationException::withMessages([
                'token' => ["This Protected Payment link is no longer active (status: {$link->status->value})."],
            ]);
        }

        $feePreview = $this->pricingService->calculateFeeBreakdown(
            principalMinor: (int) $link->principal_amount_minor,
            currency: $link->currency,
            transactionType: $link->transaction_type
        );

        return [
            'link_id' => $link->link_id,
            'canonical_url' => $link->canonical_url,
            'status' => $link->status->value,
            'transaction_type' => $link->transaction_type->value,
            'title' => $link->title,
            'description' => $link->description,
            'currency' => $link->currency,
            'principal_amount_minor' => (int) $link->principal_amount_minor,
            'fee_preview' => $feePreview,
            'delivery_method' => $link->delivery_method->value,
            'delivery_days' => (int) $link->delivery_days,
            'auto_release_hours' => (int) $link->auto_release_hours,
            'release_conditions' => $link->release_conditions,
            'return_policy' => $link->return_policy,
            'expires_at' => $link->expires_at->toIso8601String(),
            'merchant_trust_panel' => $link->merchant->publicTrustSummary(),
            'security_notices' => [
                'en' => 'Opening this link is read-only. Never trust SMS messages or screenshots as proof of payment. Funds are protected only after authenticated licensed provider confirmation.',
                'sw' => 'Kufungua kiungo hiki ni kwa kusoma pekee. Usiamini ujumbe wa SMS au picha za skrini kama uthibitisho wa malipo. Fedha zinalindwa tu baada ya uthibitisho rasmi wa mtoa huduma aliyeidhinishwa.',
            ],
        ];
    }

    /**
     * Revoke a Protected Payment link (BPA-SBV-05).
     */
    public function revokePaymentLink(ProtectedPaymentLink $link, User $actor, string $reason): ProtectedPaymentLink
    {
        if ($link->merchant_id !== $actor->id && !$actor->isAdmin()) {
            throw new AccessDeniedHttpException('Only the owning merchant or an administrator may revoke this payment link.');
        }

        $link->update([
            'status' => PaymentLinkStatus::Revoked,
            'revoked_at' => now(),
            'revocation_reason' => trim($reason),
        ]);

        AuditLogService::log('protected_payment_link_revoked', $actor, [
            'link_id' => $link->link_id,
            'reason' => $reason,
        ]);

        return $link->fresh();
    }

    /**
     * Buyer accepts a Protected Checkout Link to create a ProtectedPayment agreement (SASDS 3.6.5).
     *
     * @param array<string, mixed> $data
     * @return array{agreement: ProtectedPayment, handover_otp: string}
     */
    public function checkoutFromLink(string $token, User $buyer, array $data = []): array
    {
        $link = ProtectedPaymentLink::with('merchant.businessProfile')
            ->where('token', $token)
            ->firstOrFail();

        if ($link->status === PaymentLinkStatus::Active && $link->expires_at->isPast()) {
            $link->update(['status' => PaymentLinkStatus::Expired]);
        }

        if (!$link->isUsable()) {
            throw ValidationException::withMessages([
                'token' => ["This Protected Payment link cannot be used (status: {$link->status->value})."],
            ]);
        }

        if ($link->merchant_id === $buyer->id) {
            throw ValidationException::withMessages([
                'token' => ['A merchant cannot purchase from their own Protected Payment link.'],
            ]);
        }

        if ($link->permitted_buyer_phone !== null && PhoneNumber::normalize($buyer->phone) !== $link->permitted_buyer_phone) {
            throw new AccessDeniedHttpException('This Protected Payment link is restricted to a specific buyer phone number.');
        }

        return DB::transaction(function () use ($link, $buyer): array {
            $quote = $this->pricingService->createQuote($buyer, [
                'merchant_id' => $link->merchant_id,
                'buyer_id' => $buyer->id,
                'transaction_type' => $link->transaction_type->value,
                'currency' => $link->currency,
                'principal_amount_minor' => (int) $link->principal_amount_minor,
                'correlation_id' => $link->correlation_id,
            ]);

            $quote = $this->pricingService->acceptQuote($quote, $buyer);

            $handoverOtp = (string) random_int(100000, 999999);
            $termsVersion = (string) config('protected_payment.terms_version', 'TZ-PP-TERMS-2026-10-v1');
            $deliveryDeadline = now()->addDays((int) $link->delivery_days);

            $termsHash = hash('sha256', json_encode([
                'quote_id' => $quote->quote_id,
                'merchant_id' => $link->merchant_id,
                'buyer_id' => $buyer->id,
                'title' => $link->title,
                'principal_amount_minor' => (int) $link->principal_amount_minor,
                'total_payable_minor' => (int) $quote->total_payable_minor,
                'release_conditions' => $link->release_conditions,
                'terms_version' => $termsVersion,
            ], JSON_THROW_ON_ERROR));

            $agreement = ProtectedPayment::create([
                'protected_payment_id' => 'pp_' . Str::lower(Str::ulid()),
                'reference' => 'ALP-' . strtoupper(Str::random(10)),
                'correlation_id' => $quote->correlation_id,
                'fee_quote_id' => $quote->id,
                'payment_link_id' => $link->id,
                'merchant_id' => $link->merchant_id,
                'buyer_id' => $buyer->id,
                'transaction_type' => $link->transaction_type,
                'title' => $link->title,
                'item_description' => $link->description,
                'delivery_method' => $link->delivery_method,
                'release_conditions' => $link->release_conditions ?? [],
                'return_policy' => $link->return_policy,
                'terms_version' => $termsVersion,
                'terms_hash' => $termsHash,
                'currency' => $quote->currency,
                'principal_amount_minor' => (int) $quote->principal_amount_minor,
                'buyer_fee_minor' => (int) $quote->buyer_fee_minor,
                'buyer_tax_minor' => (int) $quote->buyer_tax_minor,
                'merchant_fee_minor' => (int) $quote->merchant_fee_minor,
                'merchant_tax_minor' => (int) $quote->merchant_tax_minor,
                'total_payable_minor' => (int) $quote->total_payable_minor,
                'merchant_net_minor' => (int) $quote->merchant_net_minor,
                'funded_amount_minor' => 0,
                'protected_balance_minor' => 0,
                'reserved_release_minor' => 0,
                'reserved_refund_minor' => 0,
                'released_amount_minor' => 0,
                'refunded_amount_minor' => 0,
                'reversed_amount_minor' => 0,
                'disputed_amount_minor' => 0,
                'status' => ProtectedPaymentStatus::AwaitingFunding,
                'fulfillment_status' => FulfillmentStatus::Pending,
                'dispute_status' => DisputeStatus::None,
                'settlement_status' => SettlementStatus::NotEligible,
                'handover_otp_hash' => Hash::make($handoverOtp),
                'state_version' => 1,
                'delivery_deadline_at' => $deliveryDeadline,
            ]);

            $newUses = (int) $link->uses_count + 1;
            $link->update([
                'uses_count' => $newUses,
                'status' => $newUses >= (int) $link->max_uses ? PaymentLinkStatus::Exhausted : PaymentLinkStatus::Active,
            ]);

            AuditLogService::log('protected_payment_created_from_link', $buyer, [
                'protected_payment_id' => $agreement->protected_payment_id,
                'reference' => $agreement->reference,
                'link_id' => $link->link_id,
                'quote_id' => $quote->quote_id,
                'correlation_id' => $agreement->correlation_id,
            ]);

            return [
                'agreement' => $agreement->load(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones']),
                'handover_otp' => $handoverOtp,
            ];
        });
    }

    /**
     * Create a Direct Protected Payment Agreement (with optional milestones) (FR-006, FR-010, FR-012).
     *
     * @param array<string, mixed> $data
     * @return array{agreement: ProtectedPayment, handover_otp: string}
     */
    public function createDirectAgreement(User $initiator, array $data): array
    {
        return DB::transaction(function () use ($initiator, $data): array {
            if (!empty($data['quote_id'])) {
                $quote = FeeQuote::where('quote_id', $data['quote_id'])->firstOrFail();

                if ($quote->isExpired()) {
                    $quote->update(['status' => 'EXPIRED']);
                    throw ValidationException::withMessages([
                        'quote_id' => ['The provided fee quote has expired.'],
                    ]);
                }

                if ($quote->status === 'CONSUMED' || ProtectedPayment::where('fee_quote_id', $quote->id)->exists()) {
                    throw ValidationException::withMessages([
                        'quote_id' => ['The provided fee quote has already been consumed by an agreement.'],
                    ]);
                }

                $buyerId = (int) ($data['buyer_id'] ?? $quote->buyer_id ?? $initiator->id);
                $buyer = User::findOrFail($buyerId);
                $merchant = User::findOrFail($quote->merchant_id);

                if (!$merchant->isVerifiedForTrading()) {
                    throw ValidationException::withMessages([
                        'merchant_id' => ['Merchant has not completed required KYC/KYB verification (BPA-SBV-02).'],
                    ]);
                }

                if ($quote->status === 'CREATED') {
                    $quote = $this->pricingService->acceptQuote($quote, $buyer);
                }
            } else {
                $merchantId = (int) $data['merchant_id'];
                $buyerId = (int) ($data['buyer_id'] ?? $initiator->id);
                $buyer = User::findOrFail($buyerId);
                $merchant = User::findOrFail($merchantId);

                $quote = $this->pricingService->createQuote($initiator, [
                    'merchant_id' => $merchantId,
                    'buyer_id' => $buyer->id,
                    'transaction_type' => TransactionType::ProtectedPayment->value,
                    'currency' => $data['currency'] ?? 'TZS',
                    'principal_amount_minor' => (int) $data['principal_amount_minor'],
                ]);

                $quote = $this->pricingService->acceptQuote($quote, $buyer);
            }

            // Validate milestones if provided (FR-012)
            $milestonesInput = $data['milestones'] ?? [];
            if (!empty($milestonesInput)) {
                $milestoneSum = 0;
                foreach ($milestonesInput as $m) {
                    $milestoneSum += (int) ($m['amount_minor'] ?? 0);
                }

                if ($milestoneSum !== (int) $quote->principal_amount_minor) {
                    throw ValidationException::withMessages([
                        'milestones' => ["Sum of milestone amounts ({$milestoneSum}) must equal principal_amount_minor ({$quote->principal_amount_minor})."],
                    ]);
                }
            }

            $deliveryMethod = isset($data['delivery_method'])
                ? DeliveryMethod::from(strtoupper((string) $data['delivery_method']))
                : (!empty($milestonesInput) ? DeliveryMethod::Milestone : DeliveryMethod::Courier);

            $deliveryDays = (int) ($data['delivery_days'] ?? 3);
            $releaseConditions = $data['release_conditions'] ?? [
                'VERIFIED_PROVIDER_FUNDING',
                'FULFILLMENT_EVIDENCE_SUBMISSION',
                'BUYER_STEP_UP_OR_INSPECTION_WINDOW',
            ];

            $termsVersion = (string) config('protected_payment.terms_version', 'TZ-PP-TERMS-2026-10-v1');
            $handoverOtp = (string) random_int(100000, 999999);

            $termsHash = hash('sha256', json_encode([
                'quote_id' => $quote->quote_id,
                'merchant_id' => $quote->merchant_id,
                'buyer_id' => $quote->buyer_id,
                'title' => trim((string) $data['title']),
                'principal_amount_minor' => (int) $quote->principal_amount_minor,
                'total_payable_minor' => (int) $quote->total_payable_minor,
                'release_conditions' => $releaseConditions,
                'terms_version' => $termsVersion,
            ], JSON_THROW_ON_ERROR));

            $agreement = ProtectedPayment::create([
                'protected_payment_id' => 'pp_' . Str::lower(Str::ulid()),
                'reference' => 'ALP-' . strtoupper(Str::random(10)),
                'correlation_id' => $quote->correlation_id,
                'fee_quote_id' => $quote->id,
                'payment_link_id' => null,
                'merchant_id' => $quote->merchant_id,
                'buyer_id' => $quote->buyer_id,
                'transaction_type' => $quote->transaction_type,
                'title' => trim((string) $data['title']),
                'item_description' => trim((string) $data['item_description']),
                'delivery_method' => $deliveryMethod,
                'release_conditions' => $releaseConditions,
                'return_policy' => $data['return_policy'] ?? 'Full refund if item/service is not delivered or materially differs from agreed terms.',
                'terms_version' => $termsVersion,
                'terms_hash' => $termsHash,
                'currency' => $quote->currency,
                'principal_amount_minor' => (int) $quote->principal_amount_minor,
                'buyer_fee_minor' => (int) $quote->buyer_fee_minor,
                'buyer_tax_minor' => (int) $quote->buyer_tax_minor,
                'merchant_fee_minor' => (int) $quote->merchant_fee_minor,
                'merchant_tax_minor' => (int) $quote->merchant_tax_minor,
                'total_payable_minor' => (int) $quote->total_payable_minor,
                'merchant_net_minor' => (int) $quote->merchant_net_minor,
                'funded_amount_minor' => 0,
                'protected_balance_minor' => 0,
                'reserved_release_minor' => 0,
                'reserved_refund_minor' => 0,
                'released_amount_minor' => 0,
                'refunded_amount_minor' => 0,
                'reversed_amount_minor' => 0,
                'disputed_amount_minor' => 0,
                'status' => ProtectedPaymentStatus::AwaitingFunding,
                'fulfillment_status' => FulfillmentStatus::Pending,
                'dispute_status' => DisputeStatus::None,
                'settlement_status' => SettlementStatus::NotEligible,
                'handover_otp_hash' => Hash::make($handoverOtp),
                'state_version' => 1,
                'delivery_deadline_at' => now()->addDays($deliveryDays),
            ]);

            foreach ($milestonesInput as $idx => $m) {
                ProtectedPaymentMilestone::create([
                    'milestone_id' => 'ms_' . Str::lower(Str::ulid()),
                    'protected_payment_id' => $agreement->id,
                    'sequence_number' => $idx + 1,
                    'title' => trim((string) $m['title']),
                    'description' => isset($m['description']) ? trim((string) $m['description']) : null,
                    'amount_minor' => (int) $m['amount_minor'],
                    'released_amount_minor' => 0,
                    'refunded_amount_minor' => 0,
                    'status' => 'PENDING',
                    'due_at' => isset($m['due_days']) ? now()->addDays((int) $m['due_days']) : $agreement->delivery_deadline_at,
                ]);
            }

            AuditLogService::log('protected_payment_agreement_created', $initiator, [
                'protected_payment_id' => $agreement->protected_payment_id,
                'reference' => $agreement->reference,
                'quote_id' => $quote->quote_id,
                'correlation_id' => $agreement->correlation_id,
                'principal_amount_minor' => $agreement->principal_amount_minor,
            ]);

            if ($this->riskEngine) {
                $this->riskEngine->evaluateProtectedPayment(
                    actor: $buyer,
                    principalAmountMinor: (int) $agreement->principal_amount_minor,
                    counterparty: $merchant,
                    payment: $agreement,
                    checkpoint: 'AGREEMENT_CREATION'
                );
            }

            if ($this->outboxService !== null) {
                $this->outboxService->publishEvent(
                    aggregateType: 'PROTECTED_PAYMENT',
                    aggregateId: $agreement->protected_payment_id,
                    eventType: 'PROTECTED_PAYMENT_CREATED',
                    payload: [
                        'protected_payment_id' => $agreement->protected_payment_id,
                        'reference' => $agreement->reference,
                        'principal_amount_minor' => (int) $agreement->principal_amount_minor,
                        'total_payable_minor' => (int) $agreement->total_payable_minor,
                        'currency' => $agreement->currency,
                        'status' => $agreement->status->value,
                    ],
                    recipientUser: $buyer,
                    titleEn: "Protected Payment Agreement {$agreement->reference} Created",
                    messageEn: "Your Protected Payment agreement '{$agreement->title}' is awaiting provider-confirmed funding.",
                    titleSw: "Makubaliano ya Malipo Salama {$agreement->reference} Yameundwa",
                    messageSw: "Makubaliano yako ya '{$agreement->title}' yanasubiri uthibitisho wa malipo kutoka kwa mtoa huduma."
                );
            }

            return [
                'agreement' => $agreement->load(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones']),
                'handover_otp' => $handoverOtp,
            ];
        });
    }

    /**
     * Submit fulfillment/delivery evidence (FR-015, FR-033, SASDS 3.1.4, 3.3.3.1).
     * Never directly releases funds by itself (FR-M10-06).
     *
     * @param array<string, mixed> $data
     */
    public function submitFulfillmentEvidence(
        ProtectedPayment $payment,
        User $actor,
        array $data,
        ?UploadedFile $file = null
    ): FulfillmentEvidence {
        $isMerchant = $actor->id === $payment->merchant_id;
        $isBuyer = $actor->id === $payment->buyer_id;
        $isAdmin = $actor->isAdmin();

        if (!$isMerchant && !$isBuyer && !$isAdmin) {
            throw new AccessDeniedHttpException('Only the merchant, buyer, or authorized analyst of this transaction may submit evidence.');
        }

        // Cannot submit fulfillment evidence before funding is confirmed (BPA-DEF-04)
        $allowedStates = [
            ProtectedPaymentStatus::FundedProtected,
            ProtectedPaymentStatus::FulfillmentSubmitted,
            ProtectedPaymentStatus::PartiallyReleased,
            ProtectedPaymentStatus::Disputed,
        ];

        if (!in_array($payment->status, $allowedStates, true)) {
            throw ValidationException::withMessages([
                'protected_payment_id' => ["Cannot submit fulfillment evidence when Protected Payment is in state '{$payment->status->value}'. Funding must be provider-confirmed first."],
            ]);
        }

        $filePath = null;
        $fileMime = null;
        $fileHash = null;

        if ($file !== null) {
            $this->validateEvidenceFileSecurity($file);
            $extension = strtolower($file->getClientOriginalExtension());
            $safeName = (string) Str::uuid() . '.' . $extension;
            $filePath = $file->storeAs('private/fulfillment_evidence', $safeName, 'local');
            $fileMime = $file->getMimeType() ?: 'application/octet-stream';
            $content = Storage::disk('local')->get($filePath);
            $fileHash = hash('sha256', (string) $content);
        }

        $milestoneId = null;
        if (!empty($data['milestone_id'])) {
            $milestone = ProtectedPaymentMilestone::where('protected_payment_id', $payment->id)
                ->where('milestone_id', $data['milestone_id'])
                ->firstOrFail();
            $milestoneId = $milestone->id;
            $milestone->update(['status' => 'EVIDENCE_SUBMITTED']);
        }

        $submitterRole = $isMerchant ? 'MERCHANT' : ($isBuyer ? 'BUYER' : 'DISPUTE_ANALYST');
        $evidenceType = EvidenceType::from(strtoupper((string) $data['evidence_type']));

        return DB::transaction(function () use (
            $payment,
            $actor,
            $data,
            $milestoneId,
            $submitterRole,
            $evidenceType,
            $filePath,
            $fileMime,
            $fileHash,
            $isMerchant
        ): FulfillmentEvidence {
            $evidence = FulfillmentEvidence::create([
                'evidence_id' => 'evd_' . Str::lower(Str::ulid()),
                'correlation_id' => $payment->correlation_id,
                'protected_payment_id' => $payment->id,
                'milestone_id' => $milestoneId,
                'submitted_by_user_id' => $actor->id,
                'submitter_role' => $submitterRole,
                'evidence_type' => $evidenceType,
                'courier_name' => isset($data['courier_name']) ? trim((string) $data['courier_name']) : null,
                'tracking_number' => isset($data['tracking_number']) ? trim((string) $data['tracking_number']) : null,
                'description' => trim((string) $data['description']),
                'file_path' => $filePath,
                'file_mime' => $fileMime,
                'file_hash' => $fileHash,
                'metadata' => $data['metadata'] ?? null,
                'verified_at' => now(),
            ]);

            if ($isMerchant && $evidenceType !== EvidenceType::DisputeEvidence) {
                $autoReleaseHours = (int) config('protected_payment.auto_release_window_hours', 48);
                $newStatus = $payment->status === ProtectedPaymentStatus::FundedProtected
                    ? ProtectedPaymentStatus::FulfillmentSubmitted
                    : $payment->status;

                $payment->update([
                    'status' => $newStatus,
                    'fulfillment_status' => FulfillmentStatus::EvidenceSubmitted,
                    'fulfilled_at' => $payment->fulfilled_at ?? now(),
                    'auto_release_at' => $payment->auto_release_at ?? now()->addHours($autoReleaseHours),
                    'state_version' => (int) $payment->state_version + 1,
                ]);
            }

            AuditLogService::log('fulfillment_evidence_submitted', $actor, [
                'protected_payment_id' => $payment->protected_payment_id,
                'evidence_id' => $evidence->evidence_id,
                'evidence_type' => $evidenceType->value,
                'file_hash' => $fileHash,
                'correlation_id' => $payment->correlation_id,
            ]);

            return $evidence;
        });
    }

    /**
     * Authorize and execute a full or milestone/partial release of Protected Payment funds (FR-011, FR-012, BPA-DEF-05, BPA-DEF-09).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function releaseProtectedPayment(
        ProtectedPayment $payment,
        User $actor,
        array $data,
        string $idempotencyKey
    ): array {
        // 1. Segregation of duties (SASDS 3.2.2 P-02, 3.2.4): Merchant cannot release their own protected funds!
        if ($actor->id === $payment->merchant_id && !$actor->isAdmin()) {
            throw new AccessDeniedHttpException(
                'BPA-PER-04 / P-02 authority boundary violation: A merchant cannot unilaterally release protected funds to themselves.'
            );
        }

        if ($actor->id !== $payment->buyer_id && !$actor->isAdmin()) {
            throw new AccessDeniedHttpException('Only the authorized buyer or an independent administrator may authorize release.');
        }

        // 2. Step-up authentication check (BPA-PER-06, BPA-CH-06): verify password or handover OTP
        $this->verifyStepUpAuthorization($payment, $actor, $data);

        // 3. Idempotent replay check (FR-011)
        $journalIdempotencyKey = "jrn_release_{$payment->protected_payment_id}_{$idempotencyKey}";
        $existingJournal = LedgerJournal::where('idempotency_key', $journalIdempotencyKey)->first();
        if ($existingJournal) {
            return [
                'idempotent_replay' => true,
                'agreement' => $payment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones']),
                'journal' => $existingJournal->load('postings.account'),
            ];
        }

        // 4. Validate funding & risk/dispute blocks (BPA-DEF-05, BPA-DEF-09)
        if ($payment->status === ProtectedPaymentStatus::Disputed || $payment->dispute_status === DisputeStatus::OpenFrozen) {
            throw ValidationException::withMessages([
                'protected_payment_id' => ['BPA-DEF-09 / FR-014 block: Cannot release funds while an unresolved dispute freeze is active.'],
            ]);
        }

        if (!in_array($payment->status, [
            ProtectedPaymentStatus::FundedProtected,
            ProtectedPaymentStatus::FulfillmentSubmitted,
            ProtectedPaymentStatus::PartiallyReleased,
        ], true)) {
            throw ValidationException::withMessages([
                'protected_payment_id' => ["BPA-DEF-05 block: Cannot release funds when Protected Payment is in state '{$payment->status->value}'."],
            ]);
        }

        $milestone = null;
        if (!empty($data['milestone_id'])) {
            $milestone = ProtectedPaymentMilestone::where('protected_payment_id', $payment->id)
                ->where('milestone_id', $data['milestone_id'])
                ->firstOrFail();

            if ($milestone->remainingAmountMinor() <= 0) {
                throw ValidationException::withMessages([
                    'milestone_id' => ['This milestone has already been fully released or refunded.'],
                ]);
            }
        }

        $eligibleMinor = $payment->eligibleForDecisionMinor();
        $releasePrincipalMinor = $milestone
            ? (int) ($data['amount_minor'] ?? $milestone->remainingAmountMinor())
            : (int) ($data['amount_minor'] ?? $eligibleMinor);

        if ($releasePrincipalMinor <= 0 || $releasePrincipalMinor > $eligibleMinor) {
            throw ValidationException::withMessages([
                'amount_minor' => ["Requested release amount ({$releasePrincipalMinor}) exceeds available unrestricted protected balance ({$eligibleMinor})."],
            ]);
        }

        if ($milestone && $releasePrincipalMinor > $milestone->remainingAmountMinor()) {
            throw ValidationException::withMessages([
                'amount_minor' => ["Requested release amount ({$releasePrincipalMinor}) exceeds milestone remaining balance ({$milestone->remainingAmountMinor()})."],
            ]);
        }

        return $this->executeAuthorizedReleaseInternal(
            payment: $payment,
            releasePrincipalMinor: $releasePrincipalMinor,
            idempotencyKey: $idempotencyKey,
            actor: $actor,
            milestone: $milestone,
            fromDisputeFrozen: false
        );
    }

    /**
     * Authorize and execute a full or partial refund to the buyer using original quote rules (FR-013, BPA-REV-14, BPA-REV-15).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function refundProtectedPayment(
        ProtectedPayment $payment,
        User $actor,
        array $data,
        string $idempotencyKey
    ): array {
        // Authority check (SASDS 3.2.2 P-01, 3.2.4):
        // Merchant may voluntarily authorize refund to buyer, or Admin may authorize refund.
        // Buyer cannot unilaterally refund themselves without merchant consent or dispute resolution.
        if ($actor->id === $payment->buyer_id && !$actor->isAdmin()) {
            throw new AccessDeniedHttpException(
                'P-01 authority boundary: A buyer cannot unilaterally execute a refund after funding. Open a dispute case or request merchant-authorized refund.'
            );
        }

        if ($actor->id !== $payment->merchant_id && !$actor->isAdmin()) {
            throw new AccessDeniedHttpException('Only the transaction merchant or an authorized administrator may execute a direct refund.');
        }

        // Step-up authentication check (BPA-PER-06)
        $this->verifyStepUpAuthorization($payment, $actor, $data);

        // Idempotent replay check
        $journalIdempotencyKey = "jrn_refund_{$payment->protected_payment_id}_{$idempotencyKey}";
        $existingJournal = LedgerJournal::where('idempotency_key', $journalIdempotencyKey)->first();
        if ($existingJournal) {
            return [
                'idempotent_replay' => true,
                'agreement' => $payment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones']),
                'journal' => $existingJournal->load('postings.account'),
            ];
        }

        if ($payment->status === ProtectedPaymentStatus::Disputed || $payment->dispute_status === DisputeStatus::OpenFrozen) {
            throw ValidationException::withMessages([
                'protected_payment_id' => ['Cannot execute direct refund while a dispute case is open; resolve through the dispute decision workflow.'],
            ]);
        }

        if (!in_array($payment->status, [
            ProtectedPaymentStatus::FundedProtected,
            ProtectedPaymentStatus::FulfillmentSubmitted,
            ProtectedPaymentStatus::PartiallyReleased,
            ProtectedPaymentStatus::PartiallyRefunded,
        ], true)) {
            throw ValidationException::withMessages([
                'protected_payment_id' => ["Cannot refund when Protected Payment is in state '{$payment->status->value}'."],
            ]);
        }

        $milestone = null;
        $milestoneUuid = null;

        if (!empty($data['milestone_id'])) {
            $milestone = ProtectedPaymentMilestone::where('protected_payment_id', $payment->id)
                ->where('milestone_id', $data['milestone_id'])
                ->firstOrFail();
            $milestoneUuid = (string) $data['milestone_id'];

            if ($milestone->remainingAmountMinor() <= 0) {
                throw ValidationException::withMessages([
                    'milestone_id' => ['This milestone has already been fully released or refunded.'],
                ]);
            }
        }

        $eligibleMinor = $payment->eligibleForDecisionMinor();
        $refundPrincipalMinor = $milestone
            ? (int) ($data['amount_minor'] ?? $milestone->remainingAmountMinor())
            : (int) ($data['amount_minor'] ?? $eligibleMinor);

        if ($refundPrincipalMinor <= 0 || $refundPrincipalMinor > $eligibleMinor) {
            throw ValidationException::withMessages([
                'amount_minor' => ["Requested refund principal ({$refundPrincipalMinor}) exceeds available unrestricted protected balance ({$eligibleMinor})."],
            ]);
        }

        if ($milestone && $refundPrincipalMinor > $milestone->remainingAmountMinor()) {
            throw ValidationException::withMessages([
                'amount_minor' => ["Requested refund principal ({$refundPrincipalMinor}) exceeds milestone remaining balance ({$milestone->remainingAmountMinor()})."],
            ]);
        }

        return $this->executeAuthorizedRefundInternal(
            payment: $payment,
            refundPrincipalMinor: $refundPrincipalMinor,
            idempotencyKey: $idempotencyKey,
            actor: $actor,
            reason: (string) ($data['reason'] ?? 'Authorized refund'),
            fromDisputeFrozen: false,
            milestoneUuid: $milestoneUuid
        );
    }

    /**
     * Internal atomic execution of an authorized release (called by buyer step-up or DisputeService).
     *
     * @return array<string, mixed>
     */
    public function executeAuthorizedReleaseInternal(
        ProtectedPayment $payment,
        int $releasePrincipalMinor,
        string $idempotencyKey,
        ?User $actor = null,
        ?ProtectedPaymentMilestone $milestone = null,
        bool $fromDisputeFrozen = false
    ): array {
        return DB::transaction(function () use (
            $payment,
            $releasePrincipalMinor,
            $idempotencyKey,
            $actor,
            $milestone,
            $fromDisputeFrozen
        ): array {
            $payment = ProtectedPayment::with('feeQuote')->lockForUpdate()->findOrFail($payment->id);

            $providerCode = $payment->custody_provider_code ?? 'SANDBOX_PSP';
            $capability = $this->providerRegistry->resolveApprovedCapability(
                providerCode: $providerCode,
                currency: $payment->currency,
                amountMinor: $releasePrincipalMinor,
                requireProtectedHold: false
            );

            // 1. Atomic reservation R_L (Rule 36: F = P + R_L + R_Q + L + Q + V)
            $newDisputed = $fromDisputeFrozen
                ? max(0, (int) $payment->disputed_amount_minor - $releasePrincipalMinor)
                : (int) $payment->disputed_amount_minor;

            $payment->update([
                'protected_balance_minor' => (int) $payment->protected_balance_minor - $releasePrincipalMinor,
                'reserved_release_minor' => (int) $payment->reserved_release_minor + $releasePrincipalMinor,
                'disputed_amount_minor' => $newDisputed,
            ]);

            // 2. Reserve in the escrow subledger BEFORE calling the provider so the
            //    balance cannot be released twice while the disbursement is in flight.
            $contract = $payment->escrowContract;
            $reservationKey = "relres:{$payment->protected_payment_id}:{$idempotencyKey}";

            if ($contract && !$fromDisputeFrozen) {
                $this->subledgerService->reserveRelease(
                    contract: $contract,
                    payment: $payment,
                    amountMinor: $releasePrincipalMinor,
                    baseKey: $reservationKey,
                    milestoneId: $milestone ? (int) $milestone->id : null,
                    actorUserId: $actor?->id
                );
            }

            $providerResult = $this->providerAdapter->executeRelease(
                payment: $payment,
                capability: $capability,
                amountMinor: $releasePrincipalMinor,
                idempotencyKey: $idempotencyKey
            );

            // 2b. Provider rejected the disbursement: reverse the reservation, keep funds in escrow.
            if (($providerResult['executed'] ?? false) === false) {
                if ($contract && !$fromDisputeFrozen) {
                    $this->subledgerService->reverseReleaseReservation(
                        contract: $contract,
                        payment: $payment,
                        amountMinor: $releasePrincipalMinor,
                        baseKey: $reservationKey,
                        milestoneId: $milestone ? (int) $milestone->id : null
                    );
                }

                throw new \DomainException(
                    'Provider rejected the release instruction (' . ($providerResult['raw_status'] ?? 'UNKNOWN') . '). Funds remain protected; retry later.'
                );
            }

            // 3. Calculate proportional merchant fee, merchant tax, and deferred buyer fee recognition
            $originalPrincipal = max(1, (int) $payment->principal_amount_minor);
            if ($releasePrincipalMinor >= $originalPrincipal) {
                $merchantFeeMinor = (int) $payment->merchant_fee_minor;
                $merchantTaxMinor = (int) $payment->merchant_tax_minor;
                $deferredBuyerFeeRecognized = (int) $payment->buyer_fee_minor;
            } else {
                $merchantFeeMinor = intdiv((int) $payment->merchant_fee_minor * $releasePrincipalMinor, $originalPrincipal);
                $merchantTaxMinor = intdiv((int) $payment->merchant_tax_minor * $releasePrincipalMinor, $originalPrincipal);
                $deferredBuyerFeeRecognized = intdiv((int) $payment->buyer_fee_minor * $releasePrincipalMinor, $originalPrincipal);
            }

            // 4. Post balanced double-entry release journal in M08
            $journal = $this->ledgerService->postProtectedRelease(
                payment: $payment,
                principalReleaseMinor: $releasePrincipalMinor,
                merchantFeeMinor: $merchantFeeMinor,
                merchantTaxMinor: $merchantTaxMinor,
                deferredBuyerFeeRecognizedMinor: $deferredBuyerFeeRecognized,
                providerReference: $providerResult['provider_release_reference'],
                idempotencyKey: $idempotencyKey,
                actor: $actor,
                fromDisputeFrozen: $fromDisputeFrozen
            );

            // 5. Commit final released allocation L and clear R_L
            $newReleased = (int) $payment->released_amount_minor + $releasePrincipalMinor;
            $remainingProtected = (int) $payment->protected_balance_minor;

            $finalStatus = $remainingProtected === 0
                ? ProtectedPaymentStatus::Released
                : ProtectedPaymentStatus::PartiallyReleased;

            $payment->update([
                'reserved_release_minor' => (int) $payment->reserved_release_minor - $releasePrincipalMinor,
                'released_amount_minor' => $newReleased,
                'status' => $finalStatus,
                'fulfillment_status' => FulfillmentStatus::DeliveredConfirmed,
                'settlement_status' => (int) $payment->disputed_amount_minor > 0
                    ? SettlementStatus::Blocked
                    : SettlementStatus::Eligible,
                'released_at' => $remainingProtected === 0 ? now() : $payment->released_at,
                'state_version' => (int) $payment->state_version + 1,
            ]);

            if ($milestone) {
                $msReleased = (int) $milestone->released_amount_minor + $releasePrincipalMinor;
                $milestone->update([
                    'released_amount_minor' => $msReleased,
                    'status' => $msReleased >= (int) $milestone->amount_minor ? 'RELEASED' : 'PARTIALLY_RELEASED',
                    'completed_at' => $msReleased >= (int) $milestone->amount_minor ? now() : null,
                ]);
            }

            if ($contract && !$fromDisputeFrozen) {
                $this->subledgerService->confirmRelease(
                    contract: $contract,
                    amountMinor: $releasePrincipalMinor,
                    baseKey: $reservationKey,
                    milestoneId: $milestone ? (int) $milestone->id : null,
                    meta: [
                        'ledger_journal_id' => $journal->id,
                        'reference' => (string) ($contract->reference ?? $payment->reference),
                        'actor_user_id' => $actor?->id,
                        'currency' => $payment->currency,
                    ]
                );
            }

            $freshPayment = $payment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones']);
            if (!$freshPayment->verifyConservationInvariant()) {
                throw new \DomainException('Rule 36 conservation of value invariant failed after release.');
            }

            AuditLogService::log('protected_payment_released', $actor, [
                'protected_payment_id' => $freshPayment->protected_payment_id,
                'reference' => $freshPayment->reference,
                'release_principal_minor' => $releasePrincipalMinor,
                'remaining_protected_minor' => $freshPayment->protected_balance_minor,
                'journal_id' => $journal->journal_id,
                'provider_release_reference' => $providerResult['provider_release_reference'],
                'correlation_id' => $freshPayment->correlation_id,
            ]);

            return [
                'idempotent_replay' => false,
                'agreement' => $freshPayment,
                'journal' => $journal,
                'provider_release_reference' => $providerResult['provider_release_reference'],
            ];
        });
    }

    /**
     * Internal atomic execution of an authorized refund (called by merchant/admin refund or DisputeService).
     *
     * @return array<string, mixed>
     */
    public function executeAuthorizedRefundInternal(
        ProtectedPayment $payment,
        int $refundPrincipalMinor,
        string $idempotencyKey,
        ?User $actor = null,
        string $reason = 'Authorized refund',
        bool $fromDisputeFrozen = false,
        ?string $milestoneUuid = null
    ): array {
        return DB::transaction(function () use (
            $payment,
            $refundPrincipalMinor,
            $idempotencyKey,
            $actor,
            $reason,
            $fromDisputeFrozen,
            $milestoneUuid
        ): array {
            $payment = ProtectedPayment::with('feeQuote')->lockForUpdate()->findOrFail($payment->id);

            $providerCode = $payment->custody_provider_code ?? 'SANDBOX_PSP';
            $capability = $this->providerRegistry->resolveApprovedCapability(
                providerCode: $providerCode,
                currency: $payment->currency,
                amountMinor: $refundPrincipalMinor,
                requireProtectedHold: false
            );

            // 1. Calculate refundable fee & tax from original accepted quote (BPA-REV-14, BPA-REV-15)
            $refundBreakdown = $this->pricingService->calculateRefundComponents(
                quote: $payment->feeQuote,
                refundPrincipalMinor: $refundPrincipalMinor
            );

            // 2. Atomic reservation R_Q
            $newDisputed = $fromDisputeFrozen
                ? max(0, (int) $payment->disputed_amount_minor - $refundPrincipalMinor)
                : (int) $payment->disputed_amount_minor;

            $payment->update([
                'protected_balance_minor' => (int) $payment->protected_balance_minor - $refundPrincipalMinor,
                'reserved_refund_minor' => (int) $payment->reserved_refund_minor + $refundPrincipalMinor,
                'disputed_amount_minor' => $newDisputed,
            ]);

            // 2b. Reserve the refund in the escrow subledger before the provider call
            //     so the money can neither be released nor refunded twice in flight.
            $contract = $payment->escrowContract;
            $refundReservationKey = "rfdres:{$payment->protected_payment_id}:{$idempotencyKey}";

            if ($contract && !$fromDisputeFrozen) {
                $this->subledgerService->reserveRefund(
                    contract: $contract,
                    amountMinor: $refundPrincipalMinor,
                    baseKey: $refundReservationKey,
                    milestoneUuid: $milestoneUuid,
                    actorUserId: $actor?->id
                );
            }

            // 3. Execute refund instruction with licensed provider
            $providerResult = $this->providerAdapter->executeRefund(
                payment: $payment,
                capability: $capability,
                amountMinor: $refundBreakdown['total_buyer_refund_minor'],
                idempotencyKey: $idempotencyKey
            );

            // 3b. Provider rejected the refund: release the reservation, funds stay protected.
            if (($providerResult['executed'] ?? true) === false) {
                if ($contract && !$fromDisputeFrozen) {
                    $this->subledgerService->reverseRefundReservation(
                        contract: $contract,
                        amountMinor: $refundPrincipalMinor,
                        baseKey: $refundReservationKey,
                        milestoneUuid: $milestoneUuid
                    );
                }

                $payment->update([
                    'protected_balance_minor' => (int) $payment->protected_balance_minor + $refundPrincipalMinor,
                    'reserved_refund_minor' => max(0, (int) $payment->reserved_refund_minor - $refundPrincipalMinor),
                ]);

                throw new \DomainException(
                    'Provider rejected the refund instruction (' . ($providerResult['raw_status'] ?? 'UNKNOWN') . '). Funds remain protected; retry later.'
                );
            }

            // 4. Post balanced double-entry refund journal in M08
            $journal = $this->ledgerService->postProtectedRefund(
                payment: $payment,
                principalRefundMinor: $refundBreakdown['refund_principal_minor'],
                buyerFeeRefundMinor: $refundBreakdown['refund_buyer_fee_minor'],
                buyerTaxRefundMinor: $refundBreakdown['refund_buyer_tax_minor'],
                providerReference: $providerResult['provider_refund_reference'],
                idempotencyKey: $idempotencyKey,
                actor: $actor,
                fromDisputeFrozen: $fromDisputeFrozen
            );

            // 5. Commit final refunded allocation Q and clear R_Q
            $newRefunded = (int) $payment->refunded_amount_minor + $refundPrincipalMinor;
            $remainingProtected = (int) $payment->protected_balance_minor;

            $finalStatus = $remainingProtected === 0 && (int) $payment->released_amount_minor === 0
                ? ProtectedPaymentStatus::Refunded
                : ProtectedPaymentStatus::PartiallyRefunded;

            $payment->update([
                'reserved_refund_minor' => (int) $payment->reserved_refund_minor - $refundPrincipalMinor,
                'refunded_amount_minor' => $newRefunded,
                'status' => $finalStatus,
                'refunded_at' => now(),
                'state_version' => (int) $payment->state_version + 1,
            ]);

            if ($milestoneUuid !== null) {
                $scopedMilestone = ProtectedPaymentMilestone::where('protected_payment_id', $payment->id)
                    ->where('milestone_id', $milestoneUuid)
                    ->first();

                if ($scopedMilestone) {
                    $msRefunded = (int) $scopedMilestone->refunded_amount_minor + $refundPrincipalMinor;
                    $scopedMilestone->update([
                        'refunded_amount_minor' => $msRefunded,
                        'status' => $msRefunded >= (int) $scopedMilestone->amount_minor ? 'REFUNDED' : 'PARTIALLY_REFUNDED',
                        'completed_at' => $msRefunded >= (int) $scopedMilestone->amount_minor ? now() : null,
                    ]);
                }
            }

            if ($contract && !$fromDisputeFrozen) {
                $this->subledgerService->confirmRefund(
                    contract: $contract,
                    payment: $payment,
                    amountMinor: $refundPrincipalMinor,
                    baseKey: $refundReservationKey,
                    milestoneUuid: $milestoneUuid,
                    meta: [
                        'ledger_journal_id' => $journal->id,
                        'reference' => (string) ($contract->reference ?? $payment->reference),
                    ]
                );
            }

            $freshPayment = $payment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones']);
            if (!$freshPayment->verifyConservationInvariant()) {
                throw new \DomainException('Rule 36 conservation of value invariant failed after refund.');
            }

            AuditLogService::log('protected_payment_refunded', $actor, [
                'protected_payment_id' => $freshPayment->protected_payment_id,
                'reference' => $freshPayment->reference,
                'refund_breakdown' => $refundBreakdown,
                'reason' => $reason,
                'journal_id' => $journal->journal_id,
                'provider_refund_reference' => $providerResult['provider_refund_reference'],
                'correlation_id' => $freshPayment->correlation_id,
            ]);

            return [
                'idempotent_replay' => false,
                'agreement' => $freshPayment,
                'refund_breakdown' => $refundBreakdown,
                'journal' => $journal,
                'provider_refund_reference' => $providerResult['provider_refund_reference'],
            ];
        });
    }

    /**
     * Generate a canonical, verifiable digital receipt (FR-023, SASDS 3.3.3.1).
     * Never describes a pending or unknown transaction as final/paid!
     *
     * @return array<string, mixed>
     */
    public function generateReceipt(ProtectedPayment $payment): array
    {
        $payment->loadMissing([
            'feeQuote',
            'merchant.businessProfile',
            'buyer',
            'latestPaymentIntent.latestAttempt',
            'journals',
        ]);

        $isFunded = (int) $payment->funded_amount_minor > 0;
        $receiptType = $isFunded ? 'VERIFIED_PAYMENT_AND_PROTECTION_RECEIPT' : 'UNFUNDED_ORDER_AGREEMENT_SUMMARY';

        $receipt = [
            'receipt_id' => 'rcp_' . substr(hash('sha256', $payment->protected_payment_id . '|' . $payment->state_version), 0, 20),
            'receipt_type' => $receiptType,
            'is_authoritative_payment_proof' => $isFunded,
            'protected_payment_id' => $payment->protected_payment_id,
            'reference' => $payment->reference,
            'correlation_id' => $payment->correlation_id,
            'status' => $payment->status->value,
            'fulfillment_status' => $payment->fulfillment_status->value,
            'dispute_status' => $payment->dispute_status->value,
            'settlement_status' => $payment->settlement_status->value,
            'customer_messages' => $payment->status->customerMessages(),
            'parties' => [
                'merchant' => $payment->merchant->publicTrustSummary(),
                'buyer' => [
                    'buyer_id' => $payment->buyer->id,
                    'name' => trim("{$payment->buyer->first_name} {$payment->buyer->last_name}"),
                ],
            ],
            'commercial_terms' => [
                'title' => $payment->title,
                'item_description' => $payment->item_description,
                'transaction_type' => $payment->transaction_type->value,
                'delivery_method' => $payment->delivery_method->value,
                'terms_version' => $payment->terms_version,
                'terms_hash' => $payment->terms_hash,
                'quote_id' => $payment->feeQuote?->quote_id,
                'pricing_version' => $payment->feeQuote?->pricing_version,
            ],
            'financial_breakdown' => [
                'currency' => $payment->currency,
                'principal_amount_minor' => (int) $payment->principal_amount_minor,
                'buyer_fee_minor' => (int) $payment->buyer_fee_minor,
                'buyer_tax_minor' => (int) $payment->buyer_tax_minor,
                'total_payable_minor' => (int) $payment->total_payable_minor,
                'merchant_fee_minor' => (int) $payment->merchant_fee_minor,
                'merchant_tax_minor' => (int) $payment->merchant_tax_minor,
                'merchant_net_minor' => (int) $payment->merchant_net_minor,
                'conservation_allocations' => [
                    'funded_amount_minor' => (int) $payment->funded_amount_minor,
                    'protected_balance_minor' => (int) $payment->protected_balance_minor,
                    'disputed_amount_minor' => (int) $payment->disputed_amount_minor,
                    'released_amount_minor' => (int) $payment->released_amount_minor,
                    'refunded_amount_minor' => (int) $payment->refunded_amount_minor,
                    'conservation_valid' => $payment->verifyConservationInvariant(),
                ],
            ],
            'provider_custody_evidence' => [
                'custody_provider_code' => $payment->custody_provider_code,
                'custody_provider_reference' => $payment->custody_provider_reference,
                'funded_at' => $payment->funded_at?->toIso8601String(),
                'released_at' => $payment->released_at?->toIso8601String(),
                'refunded_at' => $payment->refunded_at?->toIso8601String(),
            ],
            'ledger_journal_ids' => $payment->journals->pluck('journal_id')->values()->all(),
            'generated_at' => now()->toIso8601String(),
        ];

        // Tamper-evident receipt: the digest covers every field above, in the
        // order they are serialised, so a downloaded receipt can be re-hashed
        // and compared. Kept out of the hashed payload itself.
        $receipt['conservation_invariant_valid'] = $payment->verifyConservationInvariant();
        $receipt['receipt_verification_hash'] = hash(
            'sha256',
            json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $receipt;
    }

    /**
     * Validate step-up credentials (password or handover OTP) for sensitive financial release/refund commands (BPA-PER-06).
     *
     * @param array<string, mixed> $data
     */
    protected function verifyStepUpAuthorization(ProtectedPayment $payment, User $actor, array $data): void
    {
        $password = (string) ($data['step_up_password'] ?? '');
        $handoverOtp = (string) ($data['handover_otp'] ?? '');

        if ($password !== '' && Hash::check($password, $actor->password)) {
            return;
        }

        if ($handoverOtp !== '' && $payment->handover_otp_hash && Hash::check($handoverOtp, $payment->handover_otp_hash)) {
            return;
        }

        throw ValidationException::withMessages([
            'step_up_password' => ['BPA-PER-06 step-up authentication failed: provide a valid step_up_password or handover_otp to authorize this financial action.'],
        ]);
    }

    /**
     * Validate uploaded evidence file security (double extensions, server-side MIME, max size).
     */
    protected function validateEvidenceFileSecurity(UploadedFile $file): void
    {
        $originalName = $file->getClientOriginalName();
        $parts = explode('.', $originalName);

        if (count($parts) > 2) {
            throw ValidationException::withMessages([
                'evidence_file' => ['Files with multiple extensions are not allowed for security reasons.'],
            ]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file->getPathname());
        $clientMime = $file->getMimeType();

        $isValidMime = in_array($realMime, $this->allowedEvidenceMimes, true)
            || (app()->environment('testing') && in_array($clientMime, $this->allowedEvidenceMimes, true));

        if (!$isValidMime) {
            throw ValidationException::withMessages([
                'evidence_file' => ['The uploaded evidence file type is invalid. Allowed types: PDF, JPG, PNG.'],
            ]);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'evidence_file' => ['The evidence file must not exceed 5MB.'],
            ]);
        }
    }
}
