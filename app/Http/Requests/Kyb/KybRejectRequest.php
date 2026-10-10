<?php

namespace App\Http\Requests\Kyb;

use Illuminate\Foundation\Http\FormRequest;

class KybRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
