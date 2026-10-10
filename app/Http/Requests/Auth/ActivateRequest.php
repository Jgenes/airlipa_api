<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ActivateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required_without_all:email,phone', 'string'],
            'email' => ['required_without_all:identifier,phone', 'string'],
            'phone' => ['required_without_all:identifier,email', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    public function getIdentifier(): string
    {
        return (string) ($this->input('identifier') ?? $this->input('email') ?? $this->input('phone'));
    }
}
