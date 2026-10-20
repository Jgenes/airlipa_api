<?php

namespace App\Http\Requests\EscrowContract;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ShareEscrowContractRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'channels' => ['required', 'array', 'min:1', 'max:5'],
            'channels.*' => ['string', 'in:WHATSAPP,SMS,EMAIL,QR,LINK'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
