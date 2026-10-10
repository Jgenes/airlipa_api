<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class KycInitiateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nida_number' => ['required', 'string', 'size:20', 'regex:/^\d{20}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'nida_number.regex' => 'The NIDA number must be exactly 20 numeric digits.',
            'nida_number.size' => 'The NIDA number must be exactly 20 digits long.',
        ];
    }
}
