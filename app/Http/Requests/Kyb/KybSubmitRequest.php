<?php

namespace App\Http\Requests\Kyb;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class KybSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'max:100'],
            'tin_number' => ['required', 'string', 'max:50'],
            'business_type' => ['required', 'string', 'max:100'],
            'date_of_incorporation' => ['required', 'date', 'before_or_equal:today'],
            'business_address' => ['required', 'string', 'max:255'],
            'region' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'contact_person' => ['required', 'string', 'max:100'],
            'contact_phone' => ['required', 'string', new PhoneNumber],
            'contact_email' => ['required', 'string', 'email', 'max:255'],
            'registration_document' => [
                'required',
                'file',
                'max:5120', // max 5MB
                'mimes:pdf,jpg,jpeg,png',
            ],
        ];
    }
}
