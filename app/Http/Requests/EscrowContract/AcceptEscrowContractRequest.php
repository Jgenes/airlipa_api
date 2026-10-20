<?php

namespace App\Http\Requests\EscrowContract;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AcceptEscrowContractRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'otp_code' => ['required', 'string', 'size:6'],
            'full_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
