<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerJournalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'journal_id' => $this->journal_id,
            'correlation_id' => $this->correlation_id,
            'event_type' => $this->event_type,
            'posting_rule_code' => $this->posting_rule_code,
            'posting_rule_version' => $this->posting_rule_version,
            'currency' => $this->currency,
            'total_debit_minor' => (int) $this->total_debit_minor,
            'total_credit_minor' => (int) $this->total_credit_minor,
            'is_balanced' => $this->isBalanced(),
            'provider_reference' => $this->provider_reference,
            'description' => $this->description,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'postings' => $this->whenLoaded('postings', fn () => $this->postings->map(fn ($p) => [
                'posting_id' => $p->posting_id,
                'account_code' => $p->account?->account_code,
                'account_name' => $p->account?->account_name,
                'direction' => $p->direction,
                'currency' => $p->currency,
                'amount_minor' => (int) $p->amount_minor,
                'party_user_id' => $p->party_user_id,
                'narration' => $p->narration,
            ])),
        ];
    }
}
