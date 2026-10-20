<?php

return [
    /*
    |--------------------------------------------------------------------------
    | KYC / AML Trading Tiers (Module 9)
    |--------------------------------------------------------------------------
    | All limits are integer minor units (TZS cents). A user's tier is derived
    | from verification state and raised by volume; every escrow contract and
    | payout is checked against the tier BEFORE money moves.
    */
    'tiers' => [
        'TIER_0' => [
            'single_transaction_max_minor' => 0,
            'monthly_volume_max_minor' => 0,
            'requires_kyc' => true,
            'description' => 'Unverified: cannot create or accept escrow contracts.',
        ],
        'TIER_1' => [
            'single_transaction_max_minor' => 50000000,        // 500,000.00 TZS
            'monthly_volume_max_minor' => 200000000,           // 2,000,000.00 TZS
            'requires_kyc' => true,
            'description' => 'Phone + NIDA identity verified consumer.',
        ],
        'TIER_2' => [
            'single_transaction_max_minor' => 500000000,       // 5,000,000.00 TZS
            'monthly_volume_max_minor' => 2000000000,          // 20,000,000.00 TZS
            'requires_kyc' => true,
            'description' => 'Standard verification with source-of-funds declaration.',
        ],
        'TIER_3' => [
            'single_transaction_max_minor' => 5000000000,      // 50,000,000.00 TZS
            'monthly_volume_max_minor' => 20000000000,         // 200,000,000.00 TZS
            'requires_kyc' => true,
            'description' => 'Enhanced due diligence (business / high-value escrow).',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tier promotion thresholds
    |--------------------------------------------------------------------------
    | Clean users are promoted automatically; AML-flagged users are never
    | auto-promoted and require manual compliance review.
    */
    'auto_promotion' => [
        'tier_2_verified_volume_minor' => 300000000,   // 3,000,000.00 TZS lifetime funded
        'tier_3_requires_business_kyb' => true,
        'tier_3_volume_minor' => 3000000000,           // 30,000,000.00 TZS lifetime funded
    ],

    /*
    |--------------------------------------------------------------------------
    | High-risk AML behaviour
    |--------------------------------------------------------------------------
    */
    'high_risk' => [
        'manual_review_above_minor' => 1000000000,     // 10,000,000.00 TZS single contract
        'velocity_alerts_per_24h' => 10,
        'blocked_tiers' => ['TIER_0'],
    ],
];
