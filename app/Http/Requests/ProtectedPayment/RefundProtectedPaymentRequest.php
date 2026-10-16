<?php

namespace App\Http\Requests\ProtectedPayment;

use Illuminate\Foundation\Http\FormRequest;

class RefundProtectedPaymentRequest extends FormRequest
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
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
            'step_up_password' => ['required', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
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
