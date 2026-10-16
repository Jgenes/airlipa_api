<?php

namespace App\Http\Requests\ProtectedPayment;

use Illuminate\Foundation\Http\FormRequest;

class RevokePaymentLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
