<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kyc_session_id' => $this['kyc_session_id'] ?? $this->id,
            'expires_in_minutes' => $this['expires_in_minutes'] ?? 10,
            'questions' => $this['questions'] ?? [],
        ];
    }
}
