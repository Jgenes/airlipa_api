<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyLoginOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
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
