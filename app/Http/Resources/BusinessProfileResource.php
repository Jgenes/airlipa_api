<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class BusinessProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $signedDocumentUrl = null;
        $user = $request->user();

        // Only include signed document download URL if user is owner or admin
        if ($user && ($user->id === $this->user_id || $user->isAdmin())) {
            $signedDocumentUrl = URL::temporarySignedRoute(
                'kyb.document.download',
                now()->addMinutes(15),
                ['id' => $this->id]
            );
        }

        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'registration_number' => $this->registration_number,
            'tin_number' => $this->tin_number,
            'business_type' => $this->business_type,
            'date_of_incorporation' => $this->date_of_incorporation?->format('Y-m-d') ?? (string) $this->date_of_incorporation,
            'business_address' => $this->business_address,
            'region' => $this->region,
            'district' => $this->district,
            'contact_person' => $this->contact_person,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'status' => $this->status?->value ?? (string) $this->status,
            'rejection_reason' => $this->rejection_reason,
            'document_url' => $signedDocumentUrl,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
