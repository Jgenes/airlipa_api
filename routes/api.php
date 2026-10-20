<?php

use App\Http\Controllers\Api\V1\AdminKybController;
use App\Http\Controllers\Api\V1\AdminOperationsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContractShareController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EscrowContractController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\KybController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\LedgerController;
use App\Http\Controllers\Api\V1\MerchantTrustController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentLinkController;
use App\Http\Controllers\Api\V1\PayoutSettlementController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProtectedPaymentController;
use App\Http\Controllers\Api\V1\ProviderWebhookController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SupportTicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Version 1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ==========================================
    // Public Authentication Endpoints (M01)
    // ==========================================
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:register');

        Route::post('activate', [AuthController::class, 'activate'])
            ->middleware('throttle:otp_verify');

        Route::post('activation/resend', [AuthController::class, 'resendActivation'])
            ->middleware('throttle:otp_resend');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:login');

        Route::post('login/verify-otp', [AuthController::class, 'verifyLoginOtp'])
            ->middleware('throttle:otp_verify');

        Route::post('login/resend-otp', [AuthController::class, 'resendLoginOtp'])
            ->middleware('throttle:otp_resend');

        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:5,1');

        Route::post('reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:5,1');
    });

    // ==========================================
    // Public Trust Profile, Payment Link Preview & Signed Webhooks (M03, M06, M05)
    // ==========================================
    Route::get('merchants/{merchantId}/trust-profile', [MerchantTrustController::class, 'show'])
        ->name('merchants.trust-profile');

    Route::get('payment-links/{token}', [PaymentLinkController::class, 'preview'])
        ->name('payment-links.preview');

    // Public, signed, expiring escrow contract share view (Module 2)
    Route::get('contracts/share/{token}', [ContractShareController::class, 'publicShow'])
        ->name('contracts.share.public');

    Route::post('webhooks/providers/{providerCode}', [ProviderWebhookController::class, 'handleWebhook'])
        ->name('webhooks.providers.handle');

    // ==========================================
    // Document Download Endpoint (Signed/Auth)
    // ==========================================
    Route::get('kyb/document/{id}', [KybController::class, 'downloadDocument'])
        ->name('kyb.document.download');

    // ==========================================
    // KYC & KYB Verification Flow Endpoints (M02)
    // Accessible with 'kyc' scoped tokens OR full tokens
    // ==========================================
    Route::middleware(['auth:sanctum', 'ability:kyc,*'])->group(function () {
        // Individual KYC (NIDA Mock)
        Route::prefix('kyc/nida')->group(function () {
            Route::post('initiate', [KycController::class, 'initiate'])
                ->middleware('throttle:5,1');

            Route::post('verify', [KycController::class, 'verify'])
                ->middleware('throttle:5,1');
        });

        // Business KYB
        Route::prefix('kyb')->group(function () {
            Route::post('submit', [KybController::class, 'submit'])
                ->middleware('throttle:5,1');
        });
    });

    // ==========================================
    // Fully Authenticated User Endpoints
    // ==========================================
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);

        // ==========================================
        // Escrow contract share-token actions (Module 3): the counterparty may
        // hold a basic-tier (phone verified) account, so full KYC is not forced.
        // ==========================================
        Route::prefix('contracts/share/{token}')->group(function () {
            Route::post('acceptance-otp', [ContractShareController::class, 'requestAcceptanceOtp'])
                ->middleware('throttle:10,1');
            Route::post('accept', [ContractShareController::class, 'accept'])
                ->middleware('throttle:10,1');
            Route::post('reject', [ContractShareController::class, 'reject']);
            Route::post('request-changes', [ContractShareController::class, 'requestChanges']);
        });

        // Endpoints requiring verified status (or business with submitted KYB)
        Route::middleware(['ensure.fully.verified'])->group(function () {
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('profile', [ProfileController::class, 'show']);

            // ==========================================
            // Escrow contracts: draft, share, version approval, cancel (Modules 1-3)
            // ==========================================
            Route::prefix('contracts')->group(function () {
                Route::get('/', [EscrowContractController::class, 'index']);
                Route::post('/', [EscrowContractController::class, 'store']);
                Route::get('{id}', [EscrowContractController::class, 'show'])->whereNumber('id');
                Route::patch('{id}', [EscrowContractController::class, 'update'])->whereNumber('id');
                Route::get('{id}/deposit-qr', [EscrowContractController::class, 'depositQr'])->whereNumber('id');
                Route::post('{id}/share', [EscrowContractController::class, 'share'])->whereNumber('id');
                Route::post('{id}/versions/{version}/approve', [EscrowContractController::class, 'approveVersion'])
                    ->whereNumber('id')->whereNumber('version');
                Route::post('{id}/cancel', [EscrowContractController::class, 'cancel'])->whereNumber('id');
            });

            // M18: Approved Payment Providers
            Route::get('providers', [ProviderWebhookController::class, 'listProviders']);

            // M15: Pricing & Immutable Fee Quotes
            Route::prefix('pricing')->group(function () {
                Route::get('schedules/current', [PricingController::class, 'currentSchedule']);
                Route::post('quotes', [PricingController::class, 'createQuote']);
                Route::get('quotes/{quoteId}', [PricingController::class, 'showQuote']);
                Route::post('quotes/{quoteId}/accept', [PricingController::class, 'acceptQuote']);
            });

            // M06: Protected Payment Links (Social Commerce / Remote Selling)
            Route::prefix('payment-links')->group(function () {
                Route::get('/', [PaymentLinkController::class, 'index']);
                Route::post('/', [PaymentLinkController::class, 'store']);
                Route::post('{token}/checkout', [PaymentLinkController::class, 'checkout']);
                Route::patch('{linkId}/revoke', [PaymentLinkController::class, 'revoke']);
                Route::post('{linkId}/revoke', [PaymentLinkController::class, 'revoke']);
            });

            // M06, M05, M10, M14: Protected Payment Agreements, Funding, Evidence, Release, Refund & Payout
            Route::prefix('protected-payments')->group(function () {
                Route::get('/', [ProtectedPaymentController::class, 'index']);
                Route::post('/', [ProtectedPaymentController::class, 'store']);
                Route::get('{id}', [ProtectedPaymentController::class, 'show']);
                Route::post('{id}/pay', [ProtectedPaymentController::class, 'initiatePayment']);
                Route::get('{id}/deposit-qr', [ProtectedPaymentController::class, 'depositQr']);
                Route::post('{id}/delivery-qr', [ProtectedPaymentController::class, 'issueDeliveryQr'])
                    ->middleware('throttle:10,1');
                Route::post('{id}/confirm-delivery', [ProtectedPaymentController::class, 'confirmDelivery'])
                    ->middleware('throttle:10,1');
                Route::get('{id}/status-recovery', [ProtectedPaymentController::class, 'statusRecovery']);
                Route::get('{id}/payment-status', [ProtectedPaymentController::class, 'statusRecovery']);
                Route::post('{id}/simulate-provider-callback', [ProtectedPaymentController::class, 'simulateProviderCallback']);
                Route::post('{id}/evidence', [ProtectedPaymentController::class, 'submitEvidence']);
                Route::post('{id}/release', [ProtectedPaymentController::class, 'release']);
                Route::post('{id}/refund', [ProtectedPaymentController::class, 'refund']);
                Route::post('{id}/settle-payout', [PayoutSettlementController::class, 'settlePayout']);
                Route::get('{id}/receipt', [ProtectedPaymentController::class, 'receipt']);
                Route::get('{id}/journals', [ProtectedPaymentController::class, 'journals']);
                Route::post('{id}/disputes', [DisputeController::class, 'store']);
            });

            // M11: Disputes & Customer Protection
            Route::get('disputes/{caseId}', [DisputeController::class, 'show']);
            Route::post('disputes/{caseId}/resolve', [DisputeController::class, 'resolve']);

            // M14: Merchant Payout Accounts & Settlements
            Route::get('payout-accounts', [PayoutSettlementController::class, 'indexAccounts']);
            Route::post('payout-accounts', [PayoutSettlementController::class, 'storeAccount']);
            Route::get('payouts', [PayoutSettlementController::class, 'indexPayouts']);

            // M08: Operational Financial Sub-Ledger Obligations Summary
            Route::get('ledger/obligations-summary', [LedgerController::class, 'obligationsSummary']);
            Route::get('ledger/posting-rules', [LedgerController::class, 'postingRules']);

            // M12: Bilingual (EN/SW) User Notifications
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::patch('notifications/{notificationId}/read', [NotificationController::class, 'markAsRead']);

            // M13: Customer Support & Case Management
            Route::prefix('support/tickets')->group(function () {
                Route::get('/', [SupportTicketController::class, 'index']);
                Route::post('/', [SupportTicketController::class, 'store']);
                Route::get('{ticketNumber}', [SupportTicketController::class, 'show']);
                Route::post('{ticketNumber}/messages', [SupportTicketController::class, 'storeMessage']);
            });

            // M16: User & Merchant Statements
            Route::get('reports/statements', [ReportController::class, 'userStatement']);
        });
    });

    // ==========================================
    // Admin, Finance, Risk & Governance Endpoints (M07, M09, M11, M16, M17, M18)
    // ==========================================
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        Route::patch('kyb/{id}/approve', [AdminKybController::class, 'approve']);
        Route::patch('kyb/{id}/reject', [AdminKybController::class, 'reject']);

        // M18: Partner & Product Governance Emergency Controls
        Route::patch('providers/{providerCode}/status', [ProviderWebhookController::class, 'updateCapabilityStatus']);
        Route::patch('providers/{providerCode}', [ProviderWebhookController::class, 'updateCapabilityStatus']);

        // M11: Dispute Analyst Resolution
        Route::post('disputes/{caseId}/resolve', [DisputeController::class, 'resolve']);

        // M09: 3-Way Reconciliation Runs & Exceptions Queue
        Route::post('reconciliation/runs', [AdminOperationsController::class, 'executeReconciliationRun']);
        Route::get('reconciliation/exceptions', [AdminOperationsController::class, 'listReconciliationExceptions']);
        Route::patch('reconciliation/exceptions/{exceptionId}/resolve', [AdminOperationsController::class, 'resolveReconciliationException']);

        // M07: Risk Alerts & Compliance Review
        Route::get('risk/alerts', [AdminOperationsController::class, 'listRiskAlerts']);
        Route::patch('risk/alerts/{alertCode}/review', [AdminOperationsController::class, 'reviewRiskAlert']);

        // M16: Trial Balance & Regulatory Safeguarding Proof Pack
        Route::get('reports/trial-balance', [ReportController::class, 'trialBalance']);
        Route::get('reports/safeguarding-proof-pack', [ReportController::class, 'safeguardingProofPack']);

        // M17: Maker-Checker Dual Control & Tamper-Evident Audit Hash Chain Verification
        Route::post('governance/approvals', [AdminOperationsController::class, 'createApprovalRequest']);
        Route::post('governance/approvals/{approvalCode}/decide', [AdminOperationsController::class, 'decideApprovalRequest']);
        Route::get('audit-logs/verify-chain', [AdminOperationsController::class, 'verifyAuditChain']);
    });
});
