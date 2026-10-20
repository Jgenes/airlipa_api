<?php

namespace App\Http\Requests\EscrowContract;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEscrowContractRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'initiator_role' => ['required', 'string', 'in:BUYER,SELLER'],
            'counterparty_name' => ['required', 'string', 'max:120'],
            'counterparty_phone' => ['nullable', 'string', 'max:30', 'required_without:counterparty_email'],
            'counterparty_email' => ['nullable', 'email', 'max:190', 'required_without:counterparty_phone'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:60'],
            'currency' => ['nullable', 'string', 'in:TZS'],
            'amount_minor' => ['required', 'integer', 'min:100'],
            'milestones' => ['nullable', 'array'],
            'milestones.*.title' => ['required_with:milestones', 'string', 'max:160'],
            'milestones.*.description' => ['nullable', 'string', 'max:2000'],
            'milestones.*.amount_minor' => ['required_with:milestones', 'integer', 'min:1'],
            'deliverables' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array'],
            'delivery_deadline_at' => ['nullable', 'date'],
            'inspection_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'refund_terms' => ['nullable', 'string', 'max:5000'],
            'dispute_terms' => ['nullable', 'string', 'max:5000'],
            'fee_payer' => ['nullable', 'string', 'in:BUYER,SELLER,SPLIT'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $milestones = $this->input('milestones', []);

            if (empty($milestones)) {
                return;
            }

            $sum = 0;
            foreach ($milestones as $milestone) {
                $sum += (int) ($milestone['amount_minor'] ?? 0);
            }

            if ($sum !== (int) $this->input('amount_minor', 0)) {
                $validator->errors()->add(
                    'milestones',
                    "Sum of milestone amounts ({$sum}) must equal amount_minor ({$this->input('amount_minor')})."
                );
            }
        });
    }
}
