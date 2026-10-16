<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeQuoteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'quote_id' => $this->quote_id,
            'correlation_id' => $this->correlation_id,
            'pricing_version' => $this->pricing_version,
            'transaction_type' => $this->transaction_type?->value ?? (string) $this->transaction_type,
            'currency' => $this->currency,
            'principal_amount_minor' => (int) $this->principal_amount_minor,
            'buyer_fee_minor' => (int) $this->buyer_fee_minor,
            'buyer_tax_minor' => (int) $this->buyer_tax_minor,
            'merchant_fee_minor' => (int) $this->merchant_fee_minor,
            'merchant_tax_minor' => (int) $this->merchant_tax_minor,
            'total_payable_minor' => (int) $this->total_payable_minor,
            'merchant_net_minor' => (int) $this->merchant_net_minor,
            'lines' => $this->fee_lines,
            'approval_refs' => $this->approval_references,
            'terms_version' => $this->terms_version,
            'disclosure_hash' => $this->disclosure_hash,
            'status' => $this->status,
            'merchant_id' => $this->merchant_id,
            'buyer_id' => $this->buyer_id,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
