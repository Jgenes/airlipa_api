<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\KybStatus;
use App\Enums\KycStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '+255' . fake()->unique()->numerify('7########'),
            'user_type' => UserType::Individual,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => null,
            'phone_verified_at' => null,
            'password' => static::$password ??= Hash::make('Password#123456'),
            'account_status' => AccountStatus::Pending,
            'activation_token' => null,
            'is_active' => false,
            'kyc_status' => KycStatus::NotStarted,
            'kyb_status' => KybStatus::NotStarted,
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => null,
            'last_login_ip' => null,
            'role' => 'user',
            'remember_token' => Str::random(10),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'account_status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
    }

    public function individual(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => UserType::Individual,
        ]);
    }

    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => UserType::Business,
        ]);
    }

    public function kycVerified(): static
    {
        return $this->active()->individual()->state(fn (array $attributes) => [
            'kyc_status' => KycStatus::Verified,
        ]);
    }

    public function kybPending(): static
    {
        return $this->active()->business()->state(fn (array $attributes) => [
            'kyb_status' => KybStatus::Pending,
        ]);
    }

    public function kybVerified(): static
    {
        return $this->active()->business()->state(fn (array $attributes) => [
            'kyb_status' => KybStatus::Verified,
        ]);
    }

    public function kybRejected(): static
    {
        return $this->active()->business()->state(fn (array $attributes) => [
            'kyb_status' => KybStatus::Rejected,
        ]);
    }

    public function admin(): static
    {
        return $this->active()->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }
}
