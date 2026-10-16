<?php

namespace App\Http\Requests\ProtectedPayment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProtectedPaymentRequest extends FormRequest
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
            'quote_id' => ['nullable', 'string', 'exists:fee_quotes,quote_id'],
            'merchant_id' => ['required_without:quote_id', 'integer', 'exists:users,id'],
            'buyer_id' => ['nullable', 'integer', 'exists:users,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'principal_amount_minor' => ['required_without:quote_id', 'integer', 'min:100'],
            'title' => ['required', 'string', 'max:180'],
            'item_description' => ['required', 'string', 'max:2000'],
            'delivery_method' => ['nullable', 'string', Rule::in(['COURIER', 'IN_PERSON_OTP', 'DIGITAL_HANDOVER', 'MILESTONE'])],
            'delivery_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'release_conditions' => ['nullable', 'array'],
            'release_conditions.*' => ['string', 'max:100'],
            'return_policy' => ['nullable', 'string', 'max:1000'],
            'milestones' => ['nullable', 'array', 'min:1'],
            'milestones.*.title' => ['required_with:milestones', 'string', 'max:180'],
            'milestones.*.description' => ['nullable', 'string', 'max:500'],
            'milestones.*.amount_minor' => ['required_with:milestones', 'integer', 'min:100'],
            'milestones.*.due_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }
}
