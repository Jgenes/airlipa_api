<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OTP & Authentication Security Settings
    |--------------------------------------------------------------------------
    |
    | Configuration options for numeric OTP codes, expiration windows,
    | retry limits, cooldown periods, and account lockouts.
    |
    */

    'activation' => [
        'length' => (int) env('AUTH_OTP_ACTIVATION_LENGTH', 6),
        'expiry_minutes' => (int) env('AUTH_OTP_ACTIVATION_EXPIRY_MINUTES', 10),
        'max_attempts' => (int) env('AUTH_OTP_ACTIVATION_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('AUTH_OTP_ACTIVATION_COOLDOWN_SECONDS', 60),
        'max_resends_per_hour' => (int) env('AUTH_OTP_ACTIVATION_MAX_RESENDS_PER_HOUR', 3),
    ],

    'login' => [
        'length' => (int) env('AUTH_OTP_LOGIN_LENGTH', 6),
        'expiry_minutes' => (int) env('AUTH_OTP_LOGIN_EXPIRY_MINUTES', 5),
        'max_attempts' => (int) env('AUTH_OTP_LOGIN_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('AUTH_OTP_LOGIN_COOLDOWN_SECONDS', 60),
        'max_resends_per_hour' => (int) env('AUTH_OTP_LOGIN_MAX_RESENDS_PER_HOUR', 3),
        'session_token_expiry_minutes' => (int) env('AUTH_OTP_LOGIN_SESSION_MINUTES', 5),
    ],

    'contract_acceptance' => [
        'length' => (int) env('AUTH_OTP_CONTRACT_ACCEPTANCE_LENGTH', 6),
        'expiry_minutes' => (int) env('AUTH_OTP_CONTRACT_ACCEPTANCE_EXPIRY_MINUTES', 10),
        'max_attempts' => (int) env('AUTH_OTP_CONTRACT_ACCEPTANCE_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('AUTH_OTP_CONTRACT_ACCEPTANCE_COOLDOWN_SECONDS', 30),
        'max_resends_per_hour' => (int) env('AUTH_OTP_CONTRACT_ACCEPTANCE_MAX_RESENDS_PER_HOUR', 5),
    ],

    'lockout' => [
        'max_failed_logins' => (int) env('AUTH_LOCKOUT_MAX_FAILED_LOGINS', 5),
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),
    ],

    'kyc' => [
        'session_expiry_minutes' => (int) env('KYC_SESSION_EXPIRY_MINUTES', 10),
        'max_failed_attempts' => (int) env('KYC_MAX_FAILED_ATTEMPTS', 3),
        'lockout_hours' => (int) env('KYC_LOCKOUT_HOURS', 24),
    ],

    'token' => [
        'kyc_token_expiry_minutes' => (int) env('SANCTUM_KYC_TOKEN_EXPIRY_MINUTES', 60),
        'full_token_expiry_minutes' => (int) env('SANCTUM_FULL_TOKEN_EXPIRY_MINUTES', 60),
    ],

    'sms' => [
        'default_driver' => env('SMS_DRIVER', 'log'),
    ],
];
