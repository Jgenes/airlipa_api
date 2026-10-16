<?php

namespace App\Http\Requests\ProtectedPayment;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentLinkRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:2000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'principal_amount_minor' => ['required', 'integer', 'min:100'],
            'delivery_method' => ['nullable', 'string', Rule::in(['COURIER', 'IN_PERSON_OTP', 'DIGITAL_HANDOVER', 'MILESTONE'])],
            'delivery_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'auto_release_hours' => ['nullable', 'integer', 'min:12', 'max:336'],
            'release_conditions' => ['nullable', 'array'],
            'release_conditions.*' => ['string', 'max:100'],
            'return_policy' => ['nullable', 'string', 'max:1000'],
            'permitted_buyer_phone' => ['nullable', 'string', new PhoneNumber()],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'expiry_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ];
    }
}
