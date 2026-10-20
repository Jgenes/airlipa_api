<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Airlipa Protected Payment & Orchestration Configuration
    |--------------------------------------------------------------------------
    |
    | Governs pricing quotes, Protected Payment agreements, checkout links,
    | licensed provider capabilities (M18), double-entry sub-ledger accounts (M08),
    | fulfillment evidence, and dispute freezes (M11).
    |
    */

    'default_currency' => env('AIRLIPA_DEFAULT_CURRENCY', 'TZS'),

    'canonical_link_domain' => env('AIRLIPA_LINK_DOMAIN', 'https://pay.airlipa.co.tz'),

    'pricing_version' => env('AIRLIPA_PRICING_VERSION', 'TZ-R1-2026-10-01'),

    'terms_version' => env('AIRLIPA_TERMS_VERSION', 'TZ-PP-TERMS-2026-10-v1'),

    'quote_expiry_minutes' => (int) env('AIRLIPA_QUOTE_EXPIRY_MINUTES', 30),

    'intent_expiry_minutes' => (int) env('AIRLIPA_INTENT_EXPIRY_MINUTES', 30),

    'link_default_expiry_hours' => (int) env('AIRLIPA_LINK_EXPIRY_HOURS', 72),

    'auto_release_window_hours' => (int) env('AIRLIPA_AUTO_RELEASE_HOURS', 48),

    'dispute_sla_hours' => (int) env('AIRLIPA_DISPUTE_SLA_HOURS', 72),

    'dispute_appeal_hours' => (int) env('AIRLIPA_DISPUTE_APPEAL_HOURS', 168),

    'webhook_replay_window_seconds' => (int) env('AIRLIPA_WEBHOOK_REPLAY_WINDOW', 300),

    'dual_control_threshold_minor' => (int) env('AIRLIPA_DUAL_CONTROL_THRESHOLD_MINOR', 500000000), // 5,000,000.00 TZS in minor units (cents)

    /*
    |--------------------------------------------------------------------------
    | Default Approved Price Book Rules (M15)
    |--------------------------------------------------------------------------
    | All monetary values are in integer minor units (e.g., 100 minor units = 1.00 TZS).
    | Rate is in basis points (100 bps = 1.00%, 150 bps = 1.50%, 1800 bps = 18.00%).
    */
    'default_fee_rules' => [
        [
            'fee_rule_id' => 'fr_tz_r1_protection_buyer',
            'pricing_version' => 'TZ-R1-2026-10-01',
            'fee_code' => 'PROTECTION_SERVICE_FEE',
            'fee_class' => 'PROTECTION_SERVICE_FEE',
            'transaction_type' => 'PROTECTED_PAYMENT',
            'payer' => 'BUYER',
            'beneficiary' => 'AIRLIPA',
            'calculation_method' => 'PERCENTAGE',
            'flat_amount_minor' => 0,
            'rate_bps' => 150, // 1.50%
            'min_fee_minor' => 50000, // 500.00 TZS
            'max_fee_minor' => 5000000, // 50,000.00 TZS
            'currency' => 'TZS',
            'tax_code' => 'TZ_VAT_18',
            'tax_rate_bps' => 1800, // 18% VAT on fee
            'refund_policy' => 'FULLY_REFUNDABLE_BEFORE_DELIVERY',
            'recognition_policy' => 'ON_PROTECTION_FINALITY',
            'approval_reference' => 'BOT-APP-2026-PP-001',
            'disclosure_en' => 'Airlipa Protected Payment fee (1.5%, min TZS 500, max TZS 50,000) plus 18% VAT. Held under licensed partner safeguarding until release or refund.',
            'disclosure_sw' => 'Ada ya Malipo Yanayolindwa ya Airlipa (1.5%, kima cha chini TZS 500, kima cha juu TZS 50,000) pamoja na VAT 18%. Fedha zinalindwa na mshirika wa kifedha aliyeidhinishwa hadi zitakaporuhusiwa au kurejeshwa.',
        ],
        [
            'fee_rule_id' => 'fr_tz_r1_orchestration_merchant',
            'pricing_version' => 'TZ-R1-2026-10-01',
            'fee_code' => 'PAYMENT_ORCHESTRATION_FEE',
            'fee_class' => 'PAYMENT_FEE',
            'transaction_type' => 'PROTECTED_PAYMENT',
            'payer' => 'MERCHANT',
            'beneficiary' => 'AIRLIPA',
            'calculation_method' => 'PERCENTAGE',
            'flat_amount_minor' => 0,
            'rate_bps' => 100, // 1.00%
            'min_fee_minor' => 20000, // 200.00 TZS
            'max_fee_minor' => 3000000, // 30,000.00 TZS
            'currency' => 'TZS',
            'tax_code' => 'TZ_VAT_18',
            'tax_rate_bps' => 1800, // 18% VAT on fee
            'refund_policy' => 'REVERSED_ON_REFUND',
            'recognition_policy' => 'ON_PROTECTION_FINALITY',
            'approval_reference' => 'BOT-APP-2026-ORCH-002',
            'disclosure_en' => 'Merchant payment orchestration fee (1.0%, min TZS 200, max TZS 30,000) plus 18% VAT deducted upon confirmed release.',
            'disclosure_sw' => 'Ada ya usimamizi wa malipo kwa mfanyabiashara (1.0%, kima cha chini TZS 200, kima cha juu TZS 30,000) pamoja na VAT 18% inakatwa malipo yanaporuhusiwa.',
        ],
        [
            'fee_rule_id' => 'fr_tz_r1_direct_merchant',
            'pricing_version' => 'TZ-R1-2026-10-01',
            'fee_code' => 'DIRECT_PAYMENT_FEE',
            'fee_class' => 'PAYMENT_FEE',
            'transaction_type' => 'DIRECT_PAYMENT',
            'payer' => 'MERCHANT',
            'beneficiary' => 'AIRLIPA',
            'calculation_method' => 'PERCENTAGE',
            'flat_amount_minor' => 0,
            'rate_bps' => 100, // 1.00%
            'min_fee_minor' => 10000, // 100.00 TZS
            'max_fee_minor' => 2500000, // 25,000.00 TZS
            'currency' => 'TZS',
            'tax_code' => 'TZ_VAT_18',
            'tax_rate_bps' => 1800,
            'refund_policy' => 'REVERSED_ON_REFUND',
            'recognition_policy' => 'ON_FUNDING_CONFIRMATION',
            'approval_reference' => 'BOT-APP-2026-DIR-003',
            'disclosure_en' => 'Direct Payment fee (1.0%). Note: Direct Payment does not include conditional delivery hold protection.',
            'disclosure_sw' => 'Ada ya Malipo ya Moja kwa Moja (1.0%). Zingatia: Malipo ya Moja kwa Moja hayajumuishi ulinzi wa kushikilia malipo hadi mzigo ufike.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Approved Licensed Providers (M18 Capability Registry)
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | Selcom Connection (single licensed payment partner)
    |--------------------------------------------------------------------------
    | sandbox=true runs the deterministic in-process mock (local dev + tests).
    | All rails (Airtel Money, M-Pesa, Tigo Pesa/Mixx, Halopesa, Selcom Pay,
    | bank, Airtel Lipa, TanQR) collect into ONE pooled escrow account.
    */
    'selcom' => [
        'base_url' => env('SELCOM_BASE_URL', 'https://api.selcom.co.tz'),
        'api_key' => env('SELCOM_API_KEY', ''),
        'sandbox' => (bool) env('SELCOM_SANDBOX', true),
        'timeout_seconds' => (int) env('SELCOM_TIMEOUT_SECONDS', 20),
        'lipa_namba' => env('SELCOM_LIPA_NAMBA', '987654'),
        'account_name' => env('SELCOM_ACCOUNT_NAME', 'AIRLIPA ESCROW POOL'),
        'escrow_account_reference' => env('SELCOM_ESCROW_ACCOUNT', 'SELCOM-POOLED-ESCROW-001'),
        'webhook_url' => env('SELCOM_WEBHOOK_URL', 'https://api.airlipa.co.tz/api/v1/webhooks/providers/SELCOM_TZ'),
    ],

    'default_providers' => [
        [
            'provider_code' => 'SELCOM_TZ',
            'provider_name' => 'Selcom Tanzania (Licensed PSP / Escrow Custodian)',
            'legal_entity' => 'Selcom (T) Limited',
            'provider_type' => 'AGGREGATOR',
            'license_reference' => 'BOT-PSP-SELCOM-2026',
            'custody_account_reference' => 'SELCOM-POOLED-ESCROW-001',
            'supported_currencies' => ['TZS'],
            'min_amount_minor' => 10000, // 100.00 TZS
            'max_amount_minor' => 5000000000, // 50,000,000.00 TZS
            'supports_protected_hold' => true,
            'supports_refund' => true,
            'supports_payout' => true,
            'is_active' => true,
            'webhook_secret' => env('SELCOM_WEBHOOK_SECRET', 'whsec_selcom_tz_secret_2026'),
            'approval_reference' => 'M18-CAP-SELCOM-2026-00',
        ],
        [
            'provider_code' => 'MPESA_TZ',
            'provider_name' => 'Vodacom M-Pesa Tanzania',
            'legal_entity' => 'M-Pesa Limited',
            'provider_type' => 'MOBILE_MONEY',
            'license_reference' => 'BOT-EMI-MPESA-2026',
            'custody_account_reference' => 'TRUST-MPESA-SAFEGUARD-001',
            'supported_currencies' => ['TZS'],
            'min_amount_minor' => 10000, // 100.00 TZS
            'max_amount_minor' => 1000000000, // 10,000,000.00 TZS
            'supports_protected_hold' => true,
            'supports_refund' => true,
            'supports_payout' => true,
            'is_active' => true,
            'webhook_secret' => env('MPESA_WEBHOOK_SECRET', 'whsec_mpesa_tz_secret_2026'),
            'approval_reference' => 'M18-CAP-MPESA-2026-01',
        ],
        [
            'provider_code' => 'TPESA_TZ',
            'provider_name' => 'TTCL T-Pesa Tanzania',
            'legal_entity' => 'T-Pesa Limited',
            'provider_type' => 'MOBILE_MONEY',
            'license_reference' => 'BOT-EMI-TPESA-2026',
            'custody_account_reference' => 'TRUST-TPESA-SAFEGUARD-002',
            'supported_currencies' => ['TZS'],
            'min_amount_minor' => 10000,
            'max_amount_minor' => 1000000000,
            'supports_protected_hold' => true,
            'supports_refund' => true,
            'supports_payout' => true,
            'is_active' => true,
            'webhook_secret' => env('TPESA_WEBHOOK_SECRET', 'whsec_tpesa_tz_secret_2026'),
            'approval_reference' => 'M18-CAP-TPESA-2026-01',
        ],
        [
            'provider_code' => 'MIXX_TZ',
            'provider_name' => 'Mixx by Yas (Honora Tanzania)',
            'legal_entity' => 'Honora Tanzania Mobile Solutions Limited',
            'provider_type' => 'MOBILE_MONEY',
            'license_reference' => 'BOT-EMI-MIXX-2026',
            'custody_account_reference' => 'TRUST-MIXX-SAFEGUARD-003',
            'supported_currencies' => ['TZS'],
            'min_amount_minor' => 10000,
            'max_amount_minor' => 1000000000,
            'supports_protected_hold' => true,
            'supports_refund' => true,
            'supports_payout' => true,
            'is_active' => true,
            'webhook_secret' => env('MIXX_WEBHOOK_SECRET', 'whsec_mixx_tz_secret_2026'),
            'approval_reference' => 'M18-CAP-MIXX-2026-01',
        ],
        [
            'provider_code' => 'AIRTEL_TZ',
            'provider_name' => 'Airtel Money Tanzania',
            'legal_entity' => 'Airtel Money Tanzania Limited',
            'provider_type' => 'MOBILE_MONEY',
            'license_reference' => 'BOT-EMI-AIRTEL-2026',
            'custody_account_reference' => 'TRUST-AIRTEL-SAFEGUARD-004',
            'supported_currencies' => ['TZS'],
            'min_amount_minor' => 10000,
            'max_amount_minor' => 1000000000,
            'supports_protected_hold' => true,
            'supports_refund' => true,
            'supports_payout' => true,
            'is_active' => true,
            'webhook_secret' => env('AIRTEL_WEBHOOK_SECRET', 'whsec_airtel_tz_secret_2026'),
            'approval_reference' => 'M18-CAP-AIRTEL-2026-01',
        ],
        [
            'provider_code' => 'TANQR_BOT',
            'provider_name' => 'TANQR Interoperable Merchant QR (TIPS)',
            'legal_entity' => 'Licensed Acquiring Bank Partner',
            'provider_type' => 'TANQR',
            'license_reference' => 'BOT-TANQR-ACQ-2026',
            'custody_account_reference' => 'TRUST-TANQR-SAFEGUARD-005',
            'supported_currencies' => ['TZS'],
            'min_amount_minor' => 10000,
            'max_amount_minor' => 2000000000,
            'supports_protected_hold' => true,
            'supports_refund' => true,
            'supports_payout' => true,
            'is_active' => true,
            'webhook_secret' => env('TANQR_WEBHOOK_SECRET', 'whsec_tanqr_tz_secret_2026'),
            'approval_reference' => 'M18-CAP-TANQR-2026-01',
        ],
        [
            'provider_code' => 'CRDB_BANK',
            'provider_name' => 'CRDB Bank PLC',
            'legal_entity' => 'CRDB Bank PLC',
            'provider_type' => 'BANK',
            'license_reference' => 'BOT-BANK-CRDB-2026',
            'custody_account_reference' => 'TRUST-CRDB-SAFEGUARD-006',
            'supported_currencies' => ['TZS'],
            'min_amount_minor' => 100000, // 1,000.00 TZS
            'max_amount_minor' => 10000000000, // 100,000,000.00 TZS
            'supports_protected_hold' => true,
            'supports_refund' => true,
            'supports_payout' => true,
            'is_active' => true,
            'webhook_secret' => env('CRDB_WEBHOOK_SECRET', 'whsec_crdb_tz_secret_2026'),
            'approval_reference' => 'M18-CAP-CRDB-2026-01',
        ],
        [
            'provider_code' => 'SANDBOX_PSP',
            'provider_name' => 'Airlipa Certified Sandbox PSP',
            'legal_entity' => 'Airlipa Sandbox Partner Simulator',
            'provider_type' => 'SANDBOX',
            'license_reference' => 'SANDBOX-CERT-2026',
            'custody_account_reference' => 'TRUST-SANDBOX-SAFEGUARD-000',
            'supported_currencies' => ['TZS'],
            'min_amount_minor' => 100,
            'max_amount_minor' => 10000000000,
            'supports_protected_hold' => true,
            'supports_refund' => true,
            'supports_payout' => true,
            'is_active' => true,
            'webhook_secret' => env('SANDBOX_WEBHOOK_SECRET', 'whsec_sandbox_psp_secret_2026'),
            'approval_reference' => 'M18-CAP-SANDBOX-2026-01',
        ],
    ],
];
