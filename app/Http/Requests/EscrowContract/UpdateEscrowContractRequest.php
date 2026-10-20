<?php

namespace App\Http\Requests\EscrowContract;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateEscrowContractRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'counterparty_name' => ['sometimes', 'string', 'max:120'],
            'counterparty_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'counterparty_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'amount_minor' => ['sometimes', 'integer', 'min:100'],
            'milestones' => ['sometimes', 'nullable', 'array'],
            'milestones.*.title' => ['required_with:milestones', 'string', 'max:160'],
            'milestones.*.description' => ['nullable', 'string', 'max:2000'],
            'milestones.*.amount_minor' => ['required_with:milestones', 'integer', 'min:1'],
            'deliverables' => ['sometimes', 'nullable', 'array'],
            'attachments' => ['sometimes', 'nullable', 'array'],
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
            if (!$this->has('milestones')) {
                return;
            }

            $milestones = $this->input('milestones', []);
            $amount = (int) ($this->input('amount_minor') ?? 0);

            if (empty($milestones) || $amount === 0) {
                return;
            }

            $sum = 0;
            foreach ($milestones as $milestone) {
                $sum += (int) ($milestone['amount_minor'] ?? 0);
            }

            if ($sum !== $amount) {
                $validator->errors()->add('milestones', "Sum of milestone amounts ({$sum}) must equal amount_minor ({$amount}).");
            }
        });
    }
}
