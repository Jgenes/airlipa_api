<?php

namespace App\Http\Requests\EscrowContract;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RequestContractChangesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'amount_minor' => ['sometimes', 'integer', 'min:100'],
            'milestones' => ['sometimes', 'nullable', 'array'],
            'milestones.*.title' => ['required_with:milestones', 'string', 'max:160'],
            'milestones.*.description' => ['nullable', 'string', 'max:2000'],
            'milestones.*.amount_minor' => ['required_with:milestones', 'integer', 'min:1'],
            'delivery_deadline_at' => ['sometimes', 'nullable', 'date'],
            'inspection_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'refund_terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'dispute_terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'fee_payer' => ['sometimes', 'string', 'in:BUYER,SELLER,SPLIT'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $changeable = ['title', 'description', 'amount_minor', 'milestones', 'delivery_deadline_at', 'inspection_hours', 'refund_terms', 'dispute_terms', 'fee_payer'];

            if (!collect($changeable)->contains(fn (string $field): bool => $this->has($field))) {
                $validator->errors()->add('title', 'Provide at least one term to change.');
            }
        });
    }
}
