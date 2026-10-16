<?php

namespace App\Http\Requests\ProtectedPayment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateFeeQuoteRequest extends FormRequest
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
            'merchant_id' => ['required', 'integer', 'exists:users,id'],
            'buyer_id' => ['nullable', 'integer', 'exists:users,id'],
            'transaction_type' => ['nullable', 'string', Rule::in(['PROTECTED_PAYMENT', 'DIRECT_PAYMENT'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'principal_amount_minor' => ['required', 'integer', 'min:100'],
        ];
    }
}
