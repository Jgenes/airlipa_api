<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class KycInitiateRequest extends FormRequest
{
    /**
     * Alternative client-side field names accepted for the NIDA number.
     *
     * @var list<string>
     */
    protected array $aliases = [
        'nidaNumber',
        'nida',
        'nationalId',
        'national_id',
        'nida_no',
        'nidaNo',
        'id_number',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the NIDA number before validation:
     * - accept common field-name aliases (e.g. camelCase `nidaNumber`)
     * - recover the exact digits when a client sent the 20-digit number as a
     *   JSON number (PHP decodes those as floats and corrupts them otherwise)
     * - strip formatting such as spaces and dashes users commonly type
     */
    protected function prepareForValidation(): void
    {
        $value = $this->extractNidaNumber();

        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = sprintf('%.0f', $value);
        }

        if (is_string($value)) {
            $digits = preg_replace('/\D+/', '', $value);
            $value = $digits !== '' ? $digits : trim($value);
        }

        $this->merge(['nida_number' => $value]);
    }

    /**
     * Pull the NIDA number from the raw JSON body when possible (preserving
     * big integers exactly), falling back to parsed input and known aliases.
     */
    protected function extractNidaNumber(): mixed
    {
        $keys = array_merge(['nida_number'], $this->aliases);

        $decoded = json_decode((string) $this->getContent(), true, 512, JSON_BIGINT_AS_STRING);
        if (is_array($decoded)) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $decoded) && ! is_array($decoded[$key]) && $decoded[$key] !== '') {
                    return $decoded[$key];
                }
            }
        }

        foreach ($keys as $key) {
            $candidate = $this->input($key);
            if ($candidate !== null && $candidate !== '' && ! is_array($candidate)) {
                return $candidate;
            }
        }

        return $this->input('nida_number');
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
