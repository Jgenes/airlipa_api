<?php

namespace App\Http\Requests\ProtectedPayment;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseProtectedPaymentRequest extends FormRequest
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
            'milestone_id' => ['nullable', 'string', 'max:64'],
            'step_up_password' => ['nullable', 'string'],
            'handover_otp' => ['nullable', 'string', 'digits:6'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:1000'],
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
