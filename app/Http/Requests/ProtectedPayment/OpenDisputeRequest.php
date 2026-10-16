<?php

namespace App\Http\Requests\ProtectedPayment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenDisputeRequest extends FormRequest
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
            'case_type' => [
                'required',
                'string',
                Rule::in([
                    'NON_DELIVERY',
                    'MATERIALLY_DIFFERENT',
                    'PARTIAL_DELIVERY',
                    'UNAUTHORIZED_PAYMENT',
                    'PROVIDER_COMPLAINT',
                    'FRAUD_INVESTIGATION',
                ]),
            ],
            'disputed_amount_minor' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
