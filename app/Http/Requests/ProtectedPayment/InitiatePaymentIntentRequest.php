<?php

namespace App\Http\Requests\ProtectedPayment;

use Illuminate\Foundation\Http\FormRequest;

class InitiatePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_code' => ['required', 'string', 'max:50'],
            'payer_phone_or_account' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'simulate_outcome' => ['nullable', 'string', 'in:PENDING,UNKNOWN,TIMEOUT,FAILED'],
        ];
    }

    public function resolvedIdempotencyKey(): string
    {
        $headerKey = (string) $this->header('Idempotency-Key', '');
        if ($headerKey !== '') {
            return $headerKey;
        }

        return (string) $this->input('idempotency_key', '');
    }
}
