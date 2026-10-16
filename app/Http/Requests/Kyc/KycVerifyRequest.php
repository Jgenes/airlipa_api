<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class KycVerifyRequest extends FormRequest
{
    /**
     * Alternative client-side field names accepted for the KYC session id.
     *
     * @var list<string>
     */
    protected array $sessionIdAliases = [
        'kycSessionId',
        'session_id',
        'sessionId',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept common aliases for `kyc_session_id` (e.g. camelCase) and
     * coerce numeric strings to integers before validation.
     */
    protected function prepareForValidation(): void
    {
        $sessionId = $this->input('kyc_session_id');

        if ($sessionId === null) {
            foreach ($this->sessionIdAliases as $key) {
                if ($this->has($key)) {
                    $sessionId = $this->input($key);
                    break;
                }
            }
        }

        if (is_string($sessionId) && ctype_digit(trim($sessionId))) {
            $sessionId = (int) trim($sessionId);
        }

        if ($sessionId !== null) {
            $this->merge(['kyc_session_id' => $sessionId]);
        }
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
