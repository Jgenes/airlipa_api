<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EscrowContractResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'reference' => $this->reference,
            'correlation_id' => $this->correlation_id,
            'initiator_id' => $this->initiator_id,
            'initiator_role' => $this->initiator_role,
            'counterparty_name' => $this->counterparty_name,
            'counterparty_phone' => $this->counterparty_phone,
            'counterparty_email' => $this->counterparty_email,
            'counterparty_user_id' => $this->counterparty_user_id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'currency' => $this->currency,
            'amount_minor' => (int) $this->amount_minor,
            'milestones' => $this->milestones,
            'deliverables' => $this->deliverables,
            'attachments' => $this->attachments,
            'delivery_deadline_at' => $this->delivery_deadline_at?->toIso8601String(),
            'inspection_hours' => (int) $this->inspection_hours,
            'refund_terms' => $this->refund_terms,
            'dispute_terms' => $this->dispute_terms,
            'fee_payer' => $this->fee_payer?->value ?? (string) $this->fee_payer,
            'status' => $this->status?->value ?? (string) $this->status,
            'messages' => $this->status?->customerMessages(),
            'current_version' => (int) $this->current_version,
            'acceptance_expires_at' => $this->acceptance_expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'expired_at' => $this->expired_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'protected_payment_id' => $this->whenLoaded('protectedPayment', fn () => $this->protectedPayment?->protected_payment_id),
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->map(fn ($version): array => [
                'version' => (int) $version->version,
                'terms_hash' => $version->terms_hash,
                'status' => $version->status?->value ?? (string) $version->status,
                'proposed_by' => $version->proposed_by,
                'initiator_approved_at' => $version->initiator_approved_at?->toIso8601String(),
                'counterparty_approved_at' => $version->counterparty_approved_at?->toIso8601String(),
            ])),
            'shares' => $this->whenLoaded('shares', fn () => $this->shares->map(fn ($share): array => [
                'share_id' => $share->share_id,
                'channel' => $share->channel?->value ?? (string) $share->channel,
                'recipient' => $share->recipient,
                'token' => $share->token,
                'status' => $share->status?->value ?? (string) $share->status,
                'version' => (int) $share->version,
                'view_count' => (int) $share->view_count,
                'opened_at' => $share->opened_at?->toIso8601String(),
                'token_expires_at' => $share->token_expires_at?->toIso8601String(),
                'payload' => $share->payload,
                'failure_reason' => $share->failure_reason,
            ])),
            'signatures' => $this->whenLoaded('signatures', fn () => $this->signatures->map(fn ($signature): array => [
                'signature_id' => $signature->signature_id,
                'signer_role' => $signature->signer_role?->value ?? (string) $signature->signer_role,
                'full_name' => $signature->full_name,
                'phone' => $signature->phone,
                'contract_hash' => $signature->contract_hash,
                'terms_version' => $signature->terms_version,
                'otp_verified_at' => $signature->otp_verified_at?->toIso8601String(),
                'signed_at' => $signature->signed_at?->toIso8601String(),
            ])),
            'subledger' => $this->whenLoaded('subledgerAccount', fn () => $this->subledgerAccount ? [
                'account_id' => $this->subledgerAccount->account_id,
                'deposited_minor' => (int) $this->subledgerAccount->deposited_minor,
                'available_minor' => (int) $this->subledgerAccount->available_minor,
                'held_milestones_minor' => (int) $this->subledgerAccount->held_milestones_minor,
                'frozen_minor' => (int) $this->subledgerAccount->frozen_minor,
                'pending_release_minor' => (int) $this->subledgerAccount->pending_release_minor,
                'pending_refund_minor' => (int) $this->subledgerAccount->pending_refund_minor,
                'released_minor' => (int) $this->subledgerAccount->released_minor,
                'refunded_minor' => (int) $this->subledgerAccount->refunded_minor,
                'fees_minor' => (int) $this->subledgerAccount->fees_minor,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
