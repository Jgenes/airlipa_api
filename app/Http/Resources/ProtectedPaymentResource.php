<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProtectedPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'protected_payment_id' => $this->protected_payment_id,
            'reference' => $this->reference,
            'correlation_id' => $this->correlation_id,
            'transaction_type' => $this->transaction_type?->value ?? (string) $this->transaction_type,
            'title' => $this->title,
            'item_description' => $this->item_description,
            'delivery_method' => $this->delivery_method?->value ?? (string) $this->delivery_method,
            'release_conditions' => $this->release_conditions,
            'return_policy' => $this->return_policy,
            'terms_version' => $this->terms_version,
            'terms_hash' => $this->terms_hash,
            'merchant_id' => $this->merchant_id,
            'buyer_id' => $this->buyer_id,
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant->publicTrustSummary()),
            'buyer' => $this->whenLoaded('buyer', fn () => [
                'buyer_id' => $this->buyer->id,
                'name' => trim("{$this->buyer->first_name} {$this->buyer->last_name}"),
            ]),
            'money' => [
                'currency' => $this->currency,
                'principal_amount_minor' => (int) $this->principal_amount_minor,
                'buyer_fee_minor' => (int) $this->buyer_fee_minor,
                'buyer_tax_minor' => (int) $this->buyer_tax_minor,
                'merchant_fee_minor' => (int) $this->merchant_fee_minor,
                'merchant_tax_minor' => (int) $this->merchant_tax_minor,
                'total_payable_minor' => (int) $this->total_payable_minor,
                'merchant_net_minor' => (int) $this->merchant_net_minor,
                'funded_amount_minor' => (int) $this->funded_amount_minor,
                'protected_balance_minor' => (int) $this->protected_balance_minor,
                'reserved_release_minor' => (int) $this->reserved_release_minor,
                'reserved_refund_minor' => (int) $this->reserved_refund_minor,
                'released_amount_minor' => (int) $this->released_amount_minor,
                'refunded_amount_minor' => (int) $this->refunded_amount_minor,
                'reversed_amount_minor' => (int) $this->reversed_amount_minor,
                'disputed_amount_minor' => (int) $this->disputed_amount_minor,
                'eligible_for_decision_minor' => $this->eligibleForDecisionMinor(),
                'conservation_invariant_valid' => $this->verifyConservationInvariant(),
            ],
            'states' => [
                'status' => $this->status?->value ?? (string) $this->status,
                'fulfillment_status' => $this->fulfillment_status?->value ?? (string) $this->fulfillment_status,
                'dispute_status' => $this->dispute_status?->value ?? (string) $this->dispute_status,
                'settlement_status' => $this->settlement_status?->value ?? (string) $this->settlement_status,
                'customer_messages' => $this->status?->customerMessages(),
                'state_version' => (int) $this->state_version,
            ],
            'custody_provider' => [
                'provider_code' => $this->custody_provider_code,
                'provider_reference' => $this->custody_provider_reference,
                'custody_notice' => 'Actual custody and regulated safeguarding of funds remain with the approved licensed financial partner.',
            ],
            'milestones' => $this->whenLoaded('milestones', fn () => $this->milestones->map(fn ($m) => [
                'milestone_id' => $m->milestone_id,
                'sequence_number' => (int) $m->sequence_number,
                'title' => $m->title,
                'description' => $m->description,
                'amount_minor' => (int) $m->amount_minor,
                'released_amount_minor' => (int) $m->released_amount_minor,
                'refunded_amount_minor' => (int) $m->refunded_amount_minor,
                'remaining_amount_minor' => $m->remainingAmountMinor(),
                'status' => $m->status,
                'due_at' => $m->due_at?->toIso8601String(),
                'completed_at' => $m->completed_at?->toIso8601String(),
            ])),
            'quote' => $this->whenLoaded('feeQuote', fn () => new FeeQuoteResource($this->feeQuote)),
            'delivery_deadline_at' => $this->delivery_deadline_at?->toIso8601String(),
            'auto_release_at' => $this->auto_release_at?->toIso8601String(),
            'funded_at' => $this->funded_at?->toIso8601String(),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
