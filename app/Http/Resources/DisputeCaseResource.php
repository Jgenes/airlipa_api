<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisputeCaseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'case_id' => $this->case_id,
            'case_reference' => $this->case_reference,
            'correlation_id' => $this->correlation_id,
            'protected_payment_id' => $this->protectedPayment?->protected_payment_id,
            'opened_by_user_id' => $this->opened_by_user_id,
            'case_type' => $this->case_type?->value ?? (string) $this->case_type,
            'currency' => $this->currency,
            'disputed_amount_minor' => (int) $this->disputed_amount_minor,
            'reason' => $this->reason,
            'status' => $this->status,
            'primary_provider_code' => $this->primary_provider_code,
            'decision_outcome' => $this->decision_outcome?->value ?? $this->decision_outcome,
            'release_to_merchant_minor' => (int) $this->release_to_merchant_minor,
            'refund_to_buyer_minor' => (int) $this->refund_to_buyer_minor,
            'decision_reason_code' => $this->decision_reason_code,
            'decision_notes' => $this->decision_notes,
            'decided_by_user_id' => $this->decided_by_user_id,
            'sla_deadline_at' => $this->sla_deadline_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'appeal_deadline_at' => $this->appeal_deadline_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
