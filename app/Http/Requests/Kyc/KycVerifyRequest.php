<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class KycVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kyc_session_id' => ['required', 'integer'],
            'answers' => ['required', 'array', 'size:3'],
            'answers.*.question_key' => ['required', 'string'],
            'answers.*.answer' => ['required', 'string'],
        ];
    }
}
