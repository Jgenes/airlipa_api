<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FulfillmentEvidenceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'evidence_id' => $this->evidence_id,
            'correlation_id' => $this->correlation_id,
            'submitter_role' => $this->submitter_role,
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'evidence_type' => $this->evidence_type?->value ?? (string) $this->evidence_type,
            'courier_name' => $this->courier_name,
            'tracking_number' => $this->tracking_number,
            'description' => $this->description,
            'has_file' => $this->file_path !== null,
            'file_mime' => $this->file_mime,
            'file_hash' => $this->file_hash,
            'metadata' => $this->metadata,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
