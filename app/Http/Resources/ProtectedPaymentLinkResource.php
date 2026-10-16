<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProtectedPaymentLinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'link_id' => $this->link_id,
            'token' => $this->token,
            'canonical_url' => $this->canonical_url,
            'correlation_id' => $this->correlation_id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant->publicTrustSummary()),
            'title' => $this->title,
            'description' => $this->description,
            'transaction_type' => $this->transaction_type?->value ?? (string) $this->transaction_type,
            'currency' => $this->currency,
            'principal_amount_minor' => (int) $this->principal_amount_minor,
            'delivery_method' => $this->delivery_method?->value ?? (string) $this->delivery_method,
            'delivery_days' => (int) $this->delivery_days,
            'auto_release_hours' => (int) $this->auto_release_hours,
            'release_conditions' => $this->release_conditions,
            'return_policy' => $this->return_policy,
            'permitted_buyer_phone' => $this->permitted_buyer_phone,
            'max_uses' => (int) $this->max_uses,
            'uses_count' => (int) $this->uses_count,
            'status' => $this->status?->value ?? (string) $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
