<?php

namespace App\Http\Requests\ProtectedPayment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveDisputeRequest extends FormRequest
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
            'decision_outcome' => [
                'required',
                'string',
                Rule::in([
                    'FULL_REFUND_BUYER',
                    'FULL_RELEASE_MERCHANT',
                    'PARTIAL_SPLIT',
                ]),
            ],
            'release_to_merchant_minor' => ['nullable', 'integer', 'min:0'],
            'refund_to_buyer_minor' => ['nullable', 'integer', 'min:0'],
            'decision_reason_code' => ['required', 'string', 'max:64'],
            'decision_notes' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function resolvedIdempotencyKey(): string
    {
        $headerKey = (string) $this->header('Idempotency-Key', '');
        if ($headerKey !== '') {
            return $headerKey;
        }

        return (string) $this->input('idempotency_key', 'dsp_default_exec');
    }
}
