# Airlipa Secure Production REST API

A production-grade, secure REST API developed in **Laravel 11/12** and **PHP 8.2+** using **Laravel Sanctum** token authentication, clean architecture, 2-step OTP verification, NIDA mock identity verification (KYC), business verification (KYB), strict security headers, and automated test coverage.

---

## Architecture & Authentication Lifecycle

```mermaid
flowchart TD
    A[1. POST /api/v1/auth/register] -->|Hash OTP, Queue Mail & SMS| B[Status: Pending, is_active: false]
    B --> C[2. POST /api/v1/auth/activate]
    C -->|Verify 6-digit OTP| D{User Type?}
    
    D -->|Individual| E[Issue token with ability: 'kyc'<br>next_step: 'kyc']
    D -->|Business| F[Issue token with ability: 'kyc'<br>next_step: 'kyb']
    
    E --> G[3. POST /api/v1/kyc/nida/initiate<br>20-digit NIDA lookup & 3 questions]
    G --> H[4. POST /api/v1/kyc/nida/verify<br>Case-insensitive answer verification]
    H -->|kyc_status: Verified| I[next_step: 'login']
    
    F --> J[3. POST /api/v1/kyb/submit<br>Multipart upload to private disk]
    J -->|kyb_status: Pending| I
    
    I --> K[5. POST /api/v1/auth/login<br>Password check]
    K -->|Generate login OTP & signed token| L[next_step: 'verify_login_otp']
    
    L --> M[6. POST /api/v1/auth/login/verify-otp<br>Verify OTP code]
    M -->|Issue full Sanctum token with ability: '*'| N{Account State}
    
    N -->|Individual Verified / Business Verified| O[GET /api/v1/dashboard<br>GET /api/v1/profile]
    N -->|Business Pending| P[Dashboard & Profile with notice:<br>'Account verification pending']
    N -->|Business Rejected| Q[next_step: 'resubmit_kyb'<br>with rejection notice]
```

---

## Consistent Response Envelope

All API endpoints strictly output the consistent JSON envelope:

```json
{
  "success": true,
  "message": "Operation completed successfully.",
  "data": { ... },
  "errors": null,
  "next_step": "dashboard"
}
```

On validation or authorization errors:

```json
{
  "success": false,
  "message": "Validation failed.",
  "data": null,
  "errors": {
    "email": [
      "The email field must be a valid email address."
    ]
  },
  "next_step": null
}
```

---

## Security Implementation Highlights

1. **Encrypted NIDA with Blind Indexing**:
   - `nida_number` is stored encrypted using Laravel's native `'encrypted'` cast (AES-256-CBC).
   - A SHA-256 blind index hash (`nida_hash`) is indexed uniquely to prevent multiple users from linking the same NIDA number without decrypting all database rows.
2. **Timing-Safe Constant-Time Password Checks**:
   - Generic `"Invalid credentials"` returned for login failures to prevent user enumeration.
   - Fallback dummy bcrypt hash verification prevents side-channel timing leaks when an email does not exist.
3. **Lockout Controls**:
   - **Login**: Account locks for 15 minutes after 5 failed consecutive attempts (`locked_until`).
   - **KYC**: Account locks for 24 hours after 3 failed verification attempts in a 24-hour window.
4. **2-Step Login OTP**:
   - Password authentication does not issue full tokens. It generates a 6-digit numeric OTP and a signed/encrypted 5-minute `otp_session_token`.
   - Full Sanctum tokens (`['*']`, 60-minute expiry) are only issued upon valid OTP verification.
5. **Private Storage & MIME Verification**:
   - Business KYB documents are stored on a private disk (`local`), never exposed to public storage.
   - Strict server-side MIME verification via `finfo` and rejection of double extensions (e.g., `document.php.pdf`).
   - Document downloads require temporary signed URLs (`URL::temporarySignedRoute`) or owner/admin authorization.
6. **Production Security Headers**:
   - `Strict-Transport-Security: max-age=31536000; includeSubDomains`
   - `X-Content-Type-Options: nosniff`
   - `X-Frame-Options: DENY`
   - `Referrer-Policy: strict-origin-when-cross-origin`
   - `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`
7. **Rate Limiting**:
   - Register: 5 requests / min / IP
   - Login: 5 requests / min / email + IP
   - OTP Verify: 5 requests / min
   - OTP Resend: 60s cooldown and max 3 resends / hour
   - Global API: 60 requests / min

---

## Environment Variables (`.env`)

Add or update the following values in your `.env` file:

```env
APP_NAME=Airlipa
APP_ENV=local
APP_KEY=base64:IBE53rK1ypAsKN20hcH0PdEQRdhlhhM+NKFSWAF692o=
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=airlipa
DB_USERNAME=root
DB_PASSWORD=

# Session & Cache
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# Email
MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@airlipa.com"
MAIL_FROM_NAME="${APP_NAME}"

# SMS Provider Configuration
SMS_DRIVER=log
SMS_URL=https://api.africastalking.com/version1/messaging
SMS_KEY=your_sms_api_key
SMS_SENDER=AIRLIPA

# Authentication & OTP Security Settings
AUTH_OTP_ACTIVATION_LENGTH=6
AUTH_OTP_ACTIVATION_EXPIRY_MINUTES=10
AUTH_OTP_ACTIVATION_MAX_ATTEMPTS=5
AUTH_OTP_ACTIVATION_COOLDOWN_SECONDS=60
AUTH_OTP_ACTIVATION_MAX_RESENDS_PER_HOUR=3

AUTH_OTP_LOGIN_LENGTH=6
AUTH_OTP_LOGIN_EXPIRY_MINUTES=5
AUTH_OTP_LOGIN_MAX_ATTEMPTS=5
AUTH_OTP_LOGIN_COOLDOWN_SECONDS=60
AUTH_OTP_LOGIN_MAX_RESENDS_PER_HOUR=3
AUTH_OTP_LOGIN_SESSION_MINUTES=5

AUTH_LOCKOUT_MAX_FAILED_LOGINS=5
AUTH_LOCKOUT_MINUTES=15

KYC_SESSION_EXPIRY_MINUTES=10
KYC_MAX_FAILED_ATTEMPTS=3
KYC_LOCKOUT_HOURS=24

SANCTUM_KYC_TOKEN_EXPIRY_MINUTES=60
SANCTUM_FULL_TOKEN_EXPIRY_MINUTES=60
```

---

## Setup & Installation

1. **Clone & Install Dependencies**:
   ```bash
   composer install
   ```

2. **Generate Key & Configure Storage**:
   ```bash
   php artisan key:generate
   ```

3. **Run Migrations & Seed Mock NIDA Records**:
   ```bash
   php artisan migrate:fresh --seed
   ```

4. **Start Application Server & Queue Listener**:
   ```bash
   php artisan serve
   php artisan queue:listen
   ```

---

## Seeded Admin Account

- **Email**: `admin@airlipa.com`
- **Password**: `AdminSecure#2026!`
- **Role**: `admin` (Authorized for `PATCH /api/v1/admin/kyb/{id}/approve` and `reject`)

---

## 20 Mock NIDA Records (Test Numbers & Answers)

All 20 records are automatically seeded via `php artisan db:seed` (`NidaMockSeeder`):

| # | NIDA Number | Name | Date of Birth | Mother's Name | Place of Birth | Region | District |
|---|-------------|------|---------------|---------------|----------------|--------|----------|
| 1 | `19900101123450000001` | Juma Hamisi | 1990-01-01 | Fatuma | Dar es Salaam | Dar es Salaam | Ilala |
| 2 | `19920512123450000002` | Asha Bakari | 1992-05-12 | Mariam | Zanzibar | Mjini Magharibi | Mjini |
| 3 | `19881120123450000003` | Baraka Massawe | 1988-11-20 | Grace | Moshi | Kilimanjaro | Moshi Urban |
| 4 | `19950315123450000004` | Neema Mushi | 1995-03-15 | Joyce | Arusha | Arusha | Arusha City |
| 5 | `19850722123450000005` | Kelvin Shirima | 1985-07-22 | Agnes | Rombo | Kilimanjaro | Rombo |
| 6 | `19930908123450000006` | Rehema Kassim | 1993-09-08 | Zubeda | Tanga | Tanga | Tanga City |
| 7 | `19871204123450000007` | Emmanuel Makundi | 1987-12-04 | Esther | Mbeya | Mbeya | Mbeya City |
| 8 | `19940228123450000008` | Zuhura Salum | 1994-02-28 | Halima | Morogoro | Morogoro | Morogoro Urban |
| 9 | `19910617123450000009` | Godfrey Temu | 1991-06-17 | Anna | Hai | Kilimanjaro | Hai |
| 10 | `19961010123450000010` | Upendo Tarimo | 1996-10-10 | Rose | Moshi | Kilimanjaro | Moshi Rural |
| 11 | `19890405123450000011` | Dennis Kimaro | 1989-04-05 | Beatrice | Dodoma | Dodoma | Dodoma Urban |
| 12 | `19970819123450000012` | Farida Ally | 1997-08-19 | Amina | Tabora | Tabora | Tabora Urban |
| 13 | `19860325123450000013` | Innocent Mollel | 1986-03-25 | Sikitu | Monduli | Arusha | Monduli |
| 14 | `19981212123450000014` | Hadija Mwinyi | 1998-12-12 | Saida | Bagamoyo | Pwani | Bagamoyo |
| 15 | `19900930123450000015` | Geoffrey Lyimo | 1990-09-30 | Martha | Mwanza | Mwanza | Nyamagana |
| 16 | `19930707123450000016` | Zainab Rashid | 1993-07-07 | Khadija | Lindi | Lindi | Lindi Urban |
| 17 | `19841024123450000017` | Joseph Mwita | 1984-10-24 | Tabu | Musoma | Mara | Musoma Urban |
| 18 | `19950505123450000018` | Jackline Minja | 1995-05-05 | Flora | Iringa | Iringa | Iringa Urban |
| 19 | `19920118123450000019` | Rashid Athumani | 1992-01-18 | Mwanahamis | Kigoma | Kigoma | Kigoma Ujiji |
| 20 | `19960823123450000020` | Saumu Omari | 1996-08-23 | Bimkubwa | Mtwara | Mtwara | Mtwara Urban |

---

## Postman Collection

Import `postman_collection.json` located at the root of the project into Postman. It includes pre-configured environment variables and full examples for every endpoint.

---

## Service 2: Protected Payment (Conditional Payment Workflow Core)

Service 2 implements the **Airlipa One Protected Payment Core** (excluding e-commerce storefront/catalog/cart) in compliance with the SASDS architecture:

- **Partner-Backed Custody Model**: Funds are collected and held through licensed Tanzanian payment providers/banks (`MPESA_TZ`, `TPESA_TZ`, `MIXX_TZ`, `AIRTEL_TZ`, `TANQR_BOT`, `CRDB_BANK`, `SANDBOX_PSP`) while Airlipa governs the conditional release workflow and append-only double-entry operational sub-ledger.
- **Integer Minor Units Only**: All monetary values, fee quotes, ledger postings, and conservation invariants are calculated and persisted in integer minor units (`100 minor units = 1.00 TZS`) without floating-point arithmetic.
- **Explicit Domain Conservation Invariant**: Every Protected Payment strictly satisfies:
  $$F = P + R_L + R_Q + L + Q + V$$
  and $0 \le D \le P$, where $F$ is funded principal, $P$ is protected balance, $D$ is disputed/frozen balance, $R_L$ is reserved for release, $R_Q$ is reserved for refund, $L$ is released to merchant, $Q$ is refunded to buyer, and $V$ is reversed/voided.
- **Append-Only Double-Entry Sub-Ledger (`M08`)**: Every financial state transition posts balanced debit/credit journals across `1100-PROVIDER-CLEARING`, `2100-PROTECTED-CUSTOMER-FUNDS`, `2200-MERCHANT-PAYABLE`, `2300-REFUND-PAYABLE`, `2400-DEFERRED-PLATFORM-FEE`, `2500-TAX-PAYABLE-VAT`, and `4100-EARNED-PLATFORM-REVENUE`.

### Protected Payment API Endpoints (`/api/v1`)

| Module | Method | Endpoint | Description |
|--------|--------|----------|-------------|
| **M03 Merchant Trust** | `GET` | `/api/v1/merchants/{merchantId}/trust-profile` | Public merchant verification badge, KYB/KYC tier, and track record |
| **M18 Provider Governance** | `GET` | `/api/v1/providers` | List licensed payment provider capabilities (`MPESA_TZ`, `TPESA_TZ`, `MIXX_TZ`, `AIRTEL_TZ`, `TANQR_BOT`, `CRDB_BANK`, `SANDBOX_PSP`) |
| **M18 Provider Governance** | `PATCH` | `/api/v1/admin/providers/{providerCode}/status` | Admin governance endpoint to activate or suspend a rail |
| **M15 Pricing & Quotes** | `GET` | `/api/v1/pricing/schedules/current` | Retrieve effective-dated price book rules |
| **M15 Pricing & Quotes** | `POST` | `/api/v1/pricing/quotes` | Create a deterministic, versioned `FeeQuote` (`15 min` TTL) |
| **M15 Pricing & Quotes** | `GET` | `/api/v1/pricing/quotes/{quoteId}` | Inspect a `FeeQuote` |
| **M15 Pricing & Quotes** | `POST` | `/api/v1/pricing/quotes/{quoteId}/accept` | Accept a `FeeQuote` prior to funding |
| **M06 Checkout Links** | `GET` | `/api/v1/payment-links/{token}` | Public, strictly read-only preview of a Protected Payment Link (`BPA-CH-14`) |
| **M06 Checkout Links** | `POST` | `/api/v1/payment-links` | Create a shareable Protected Payment Link (verified merchants only) |
| **M06 Checkout Links** | `GET` | `/api/v1/payment-links` | List merchant's Protected Payment Links |
| **M06 Checkout Links** | `PATCH` | `/api/v1/payment-links/{linkId}/revoke` | Revoke an active Protected Payment Link |
| **M06 Checkout Links** | `POST` | `/api/v1/payment-links/{token}/checkout` | Buyer accepts link terms & creates a `ProtectedPayment` agreement |
| **M06 Protected Payments** | `POST` | `/api/v1/protected-payments` | Directly create a `ProtectedPayment` agreement (supports milestones) |
| **M06 Protected Payments** | `GET` | `/api/v1/protected-payments` | List buyer/merchant Protected Payment agreements |
| **M06 Protected Payments** | `GET` | `/api/v1/protected-payments/{id}` | Retrieve agreement state, milestones, evidence, disputes, and ledger trail |
| **M05 Orchestration** | `POST` | `/api/v1/protected-payments/{id}/pay` | Initiate an idempotent `PaymentIntent` & `PaymentAttempt` |
| **M05 Orchestration** | `GET` | `/api/v1/protected-payments/{id}/status-recovery` | Re-query non-final (`PENDING`/`UNKNOWN`) payment attempt status |
| **M05 Webhooks** | `POST` | `/api/v1/webhooks/providers/{providerCode}` | Receive HMAC-SHA256 authenticated provider callbacks |
| **M05 Sandbox Helper** | `POST` | `/api/v1/protected-payments/{id}/simulate-provider-callback` | Local/testing helper to sign and dispatch a provider callback (`SUCCEEDED`, `PENDING`, `UNKNOWN`, `FAILED`) |
| **M10 Evidence** | `POST` | `/api/v1/protected-payments/{id}/evidence` | Submit delivery/fulfillment evidence with SHA-256 integrity hash |
| **M06 Release** | `POST` | `/api/v1/protected-payments/{id}/release` | Step-up authenticated (`handover_otp` or `step_up_password`) full or milestone release |
| **M06 Refund** | `POST` | `/api/v1/protected-payments/{id}/refund` | Execute full or partial refund using original `FeeQuote` version |
| **M14 Payouts** | `GET` | `/api/v1/payout-accounts` | List merchant verified payout accounts |
| **M14 Payouts** | `POST` | `/api/v1/payout-accounts` | Register a verified merchant payout account with step-up password (`BPA-DATA-03`) |
| **M14 Payouts** | `POST` | `/api/v1/protected-payments/{id}/settle-payout` | Execute merchant payout settlement for released, undisputed funds |
| **M14 Payouts** | `GET` | `/api/v1/payouts` | List merchant payout instructions & settlement batches |
| **M06 Receipt** | `GET` | `/api/v1/protected-payments/{id}/receipt` | Generate a cryptographically verifiable digital receipt (`FR-023`) |
| **M08 Journals** | `GET` | `/api/v1/protected-payments/{id}/journals` | Retrieve balanced double-entry sub-ledger journals for an agreement |
| **M11 Disputes** | `POST` | `/api/v1/protected-payments/{id}/disputes` | Open a dispute (`NON_DELIVERY`, `MATERIALLY_DIFFERENT`, `PARTIAL_DELIVERY`, etc.) and atomically freeze funds |
| **M11 Disputes** | `GET` | `/api/v1/disputes/{caseId}` | View dispute case details |
| **M11 Disputes** | `POST` | `/api/v1/admin/disputes/{caseId}/resolve` | Admin dispute resolution (`FULL_RELEASE_MERCHANT`, `FULL_REFUND_BUYER`, `PARTIAL_SPLIT`) |
| **M08 Sub-Ledger** | `GET` | `/api/v1/ledger/obligations-summary` | Real-time customer-funds obligation vs. provider clearing balance |
| **M12 Notifications** | `GET` | `/api/v1/notifications` | Bilingual (`?lang=en` / `?lang=sw`) user notifications via Transactional Outbox |
| **M12 Notifications** | `PATCH` | `/api/v1/notifications/{notificationId}/read` | Mark notification as read |
| **M13 Support Cases** | `POST` | `/api/v1/support/tickets` | Open customer support case linked to a transaction or dispute |
| **M13 Support Cases** | `GET` | `/api/v1/support/tickets` | List user/admin support cases |
| **M13 Support Cases** | `POST` | `/api/v1/support/tickets/{ticketNumber}/messages` | Reply to a support ticket |
| **M16 Reporting** | `GET` | `/api/v1/reports/statements` | User/merchant statement with fee, VAT, and net settlement breakdown |
| **M16 Reporting** | `GET` | `/api/v1/admin/reports/trial-balance` | Complete operational sub-ledger trial balance |
| **M16 Reporting** | `GET` | `/api/v1/admin/reports/safeguarding-proof-pack` | Regulatory safeguarding & conservation invariant proof pack |
| **M09 Reconciliation** | `POST` | `/api/v1/admin/reconciliation/runs` | Execute 3-way reconciliation run (Statement vs Orchestration vs Sub-Ledger) |
| **M09 Reconciliation** | `GET` | `/api/v1/admin/reconciliation/exceptions` | List open/resolved reconciliation exceptions |
| **M09 Reconciliation** | `PATCH` | `/api/v1/admin/reconciliation/exceptions/{exceptionId}/resolve` | Resolve a reconciliation exception with audit trail |
| **M07 Risk Engine** | `GET` | `/api/v1/admin/risk/alerts` | List velocity, high-value, and anomaly risk alerts |
| **M07 Risk Engine** | `PATCH` | `/api/v1/admin/risk/alerts/{alertCode}/review` | Compliance review of a risk alert |
| **M17 Dual Control** | `POST` | `/api/v1/admin/governance/approvals` | Maker initiates a dual-control governance request (`P-05`, `FR-036`) |
| **M17 Dual Control** | `POST` | `/api/v1/admin/governance/approvals/{approvalCode}/decide` | Independent checker approves/rejects dual-control request (`Maker != Checker`) |
| **M17 Audit Chain** | `GET` | `/api/v1/admin/audit-logs/verify-chain` | Verify tamper-evident SHA-256 hash chain across all audit logs (`FR-030`) |

---

## Running Automated Feature Tests

Execute the Pest test suite:

```bash
php artisan test
```

Or target specific feature suites:

```bash
php artisan test --filter=RegistrationTest
php artisan test --filter=ActivationTest
php artisan test --filter=LoginTest
php artisan test --filter=PasswordResetTest
php artisan test --filter=KycTest
php artisan test --filter=KybTest
php artisan test --filter=DashboardProfileTest
php artisan test --filter=SecurityTest
php artisan test --filter=PricingAndQuoteTest
php artisan test --filter=PaymentLinkAndAgreementTest
php artisan test --filter=OrchestrationAndWebhookTest
php artisan test --filter=LifecycleReleaseRefundDisputeTest
```
