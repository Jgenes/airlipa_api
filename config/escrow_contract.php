<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Escrow Contract Layer Configuration (Phase 1)
    |--------------------------------------------------------------------------
    |
    | Governs the two-party escrow contract lifecycle that sits in front of
    | Protected Payments: drafting, multi-channel sharing (WhatsApp / SMS /
    | Email / QR / secure link), counterparty acceptance with OTP-backed
    | e-signature, counter-proposal versioning and auto-expiry.
    |
    */

    // Public base URL embedded in share links and QR payloads (mobile-first PWA route).
    'public_base_url' => env('ESCROW_CONTRACT_PUBLIC_URL', 'https://pay.airlipa.co.tz/c'),

    // Lifetime of a signed share token before the link/QR stops resolving.
    'share_token_ttl_hours' => (int) env('ESCROW_CONTRACT_TOKEN_TTL_HOURS', 72),

    // Default window given to the counterparty to accept a shared contract.
    'default_acceptance_window_hours' => (int) env('ESCROW_CONTRACT_ACCEPTANCE_HOURS', 72),

    // Default inspection / acceptance period (hours) applied after funding.
    'default_inspection_hours' => (int) env('ESCROW_CONTRACT_INSPECTION_HOURS', 72),

    // HMAC secret for signed, expiring share tokens (falls back to APP_KEY).
    'token_secret' => env('ESCROW_CONTRACT_TOKEN_SECRET'),

    // Channels enabled for sharing; disable per channel during incidents.
    'enabled_channels' => array_filter(explode(',', (string) env('ESCROW_CONTRACT_CHANNELS', 'WHATSAPP,SMS,EMAIL,QR,LINK'))),
];
