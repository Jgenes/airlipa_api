<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordDoesNotContainUserInfo;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }

        if ($this->has('phone') && is_string($this->phone)) {
            $this->merge(['phone' => PhoneNumber::normalize($this->phone)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $emailRule = app()->environment('testing')
            ? ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email']
            : ['required', 'string', 'email:rfc,dns', 'max:255', 'unique:users,email'];

        return [
            'first_name' => ['required', 'string', 'max:50', 'regex:/^[\pL\s\-]+$/u'],
            'last_name' => ['required', 'string', 'max:50', 'regex:/^[\pL\s\-]+$/u'],
            'email' => $emailRule,
            'phone' => ['required', 'string', new PhoneNumber, 'unique:users,phone'],
            'account_type' => ['required', 'string', 'in:individual,business,Individual,Business'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
                new PasswordDoesNotContainUserInfo,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.regex' => 'First name must contain letters, spaces, or hyphens only.',
            'last_name.regex' => 'Last name must contain letters, spaces, or hyphens only.',
            'account_type.in' => 'Account type must be either individual or business.',
        ];
    }
}
