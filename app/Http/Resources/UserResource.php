<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'user_type' => $this->user_type?->value ?? (string) $this->user_type,
            'account_status' => $this->account_status?->value ?? (string) $this->account_status,
            'kyc_status' => $this->kyc_status?->value ?? (string) $this->kyc_status,
            'kyb_status' => $this->kyb_status?->value ?? (string) $this->kyb_status,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
