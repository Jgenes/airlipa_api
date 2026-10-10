<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordDoesNotContainUserInfo implements ValidationRule, DataAwareRule
{
    /**
     * All of the data under validation.
     *
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * Set the data under validation.
     *
     * @param array<string, mixed> $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        $passwordLower = mb_strtolower($value);

        // Check first name
        $firstName = isset($this->data['first_name']) && is_string($this->data['first_name'])
            ? mb_strtolower(trim($this->data['first_name']))
            : null;
        if ($firstName && mb_strlen($firstName) >= 3 && str_contains($passwordLower, $firstName)) {
            $fail('The :attribute must not contain your first name.');
            return;
        }

        // Check last name
        $lastName = isset($this->data['last_name']) && is_string($this->data['last_name'])
            ? mb_strtolower(trim($this->data['last_name']))
            : null;
        if ($lastName && mb_strlen($lastName) >= 3 && str_contains($passwordLower, $lastName)) {
            $fail('The :attribute must not contain your last name.');
            return;
        }

        // Check email username part
        $email = isset($this->data['email']) && is_string($this->data['email'])
            ? mb_strtolower(trim($this->data['email']))
            : null;
        if ($email) {
            $emailUsername = explode('@', $email)[0];
            if (mb_strlen($emailUsername) >= 3 && str_contains($passwordLower, $emailUsername)) {
                $fail('The :attribute must not contain parts of your email address.');
            }
        }
    }
}
