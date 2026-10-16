<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyLoginOtpRequest extends FormRequest
{
    /**
     * Alternative client-side field names accepted for the OTP session token.
     *
     * @var list<string>
     */
    protected array $tokenAliases = [
        'otpSessionToken',
        'session_token',
        'sessionToken',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize input before validation:
     * - accept common aliases for `otp_session_token` (e.g. camelCase)
     * - accept `otp` as an alias for `code`
     */
    protected function prepareForValidation(): void
    {
        $token = $this->input('otp_session_token');

        if (($token === null || $token === '') && is_string($this->getContent())) {
            $decoded = json_decode((string) $this->getContent(), true);
            $candidates = array_merge(['otp_session_token'], $this->tokenAliases);

            if (is_array($decoded)) {
                foreach ($candidates as $key) {
                    if (array_key_exists($key, $decoded) && ! is_array($decoded[$key]) && $decoded[$key] !== null && $decoded[$key] !== '') {
                        $token = $decoded[$key];
                        break;
                    }
                }
            }
        }

        if (is_string($token)) {
            $token = trim($token);
        }

        if ($token !== null && $token !== '') {
            $this->merge(['otp_session_token' => $token]);
        }

        $code = $this->input('code') ?? $this->input('otp');

        if (is_int($code)) {
            $code = (string) $code;
        }

        if ($code !== null) {
            $this->merge(['code' => $code]);
        }
    }

    public function rules(): array
    {
        return [
            'otp_session_token' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
