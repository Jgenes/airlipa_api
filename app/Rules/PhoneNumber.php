<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneNumber implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('The :attribute must be a valid phone number string.');
            return;
        }

        $normalized = self::normalize($value);

        // Validate E.164 standard: + followed by 7 to 15 digits
        if (!preg_match('/^\+[1-9]\d{6,14}$/', $normalized)) {
            $fail('The :attribute must be a valid international phone number format (e.g. +2557XXXXXXXX).');
        }
    }

    /**
     * Normalize a phone number to standard E.164 format.
     * Defaults local Tanzanian format (06... or 07...) to +255.
     */
    public static function normalize(string $phone): string
    {
        // Strip out whitespace, hyphens, and brackets
        $cleaned = preg_replace('/[\s\-\(\)]+/', '', trim($phone));

        if ($cleaned === null || $cleaned === '') {
            return '';
        }

        // If starts with 0 and has 10 digits (e.g. 07XXXXXXXX or 06XXXXXXXX)
        if (preg_match('/^0([67]\d{8})$/', $cleaned, $matches)) {
            return '+255' . $matches[1];
        }

        // If starts with 255 without a plus
        if (preg_match('/^255([67]\d{8})$/', $cleaned, $matches)) {
            return '+255' . $matches[1];
        }

        // If it starts with a plus, keep it
        if (str_starts_with($cleaned, '+')) {
            return $cleaned;
        }

        // If already international without leading plus (e.g. 255...)
        if (preg_match('/^\d{9,15}$/', $cleaned)) {
            return '+' . $cleaned;
        }

        return $cleaned;
    }
}
