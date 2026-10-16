<?php

namespace App\Http\Requests\ProtectedPayment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitFulfillmentEvidenceRequest extends FormRequest
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
            'evidence_type' => [
                'required',
                'string',
                Rule::in([
                    'DISPATCH_PROOF',
                    'COURIER_TRACKING',
                    'DELIVERY_OTP_CONFIRMATION',
                    'DELIVERY_PHOTO_DOC',
                    'DIGITAL_COMPLETION',
                    'DISPUTE_EVIDENCE',
                ]),
            ],
            'milestone_id' => ['nullable', 'string', 'max:64'],
            'courier_name' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'evidence_file' => ['nullable', 'file'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
