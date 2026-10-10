<?php

namespace App\Http\Resources;

use App\Enums\KybStatus;
use App\Enums\UserType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
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
        ];

        // Masked NIDA for Individual users
        if ($this->user_type === UserType::Individual) {
            $latestKyc = $this->latestKycVerification;
            $data['nida_number_masked'] = $latestKyc?->masked_nida_number ?? null;
        }

        // Business Profile for Business users
        if ($this->user_type === UserType::Business) {
            $data['business_profile'] = $this->businessProfile
                ? new BusinessProfileResource($this->businessProfile)
                : null;

            if ($this->kyb_status === KybStatus::Pending) {
                $data['verification_notice'] = 'Account verification pending';
            } elseif ($this->kyb_status === KybStatus::Rejected) {
                $reason = $this->businessProfile?->rejection_reason ?? 'Documentation did not meet compliance criteria';
                $data['verification_notice'] = "Account verification was rejected: {$reason}";
            }
        }

        return $data;
    }
}
