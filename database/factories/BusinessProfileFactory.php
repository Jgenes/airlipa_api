<?php

namespace Database\Factories;

use App\Enums\BusinessProfileStatus;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessProfile>
 */
class BusinessProfileFactory extends Factory
{
    protected $model = BusinessProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'business_name' => fake()->company(),
            'registration_number' => 'BRELA-' . fake()->numerify('######'),
            'tin_number' => fake()->numerify('1########'),
            'business_type' => 'Limited Liability Company',
            'date_of_incorporation' => fake()->date('Y-m-d', '-3 years'),
            'business_address' => fake()->streetAddress(),
            'region' => 'Dar es Salaam',
            'district' => 'Kinondoni',
            'contact_person' => fake()->name(),
            'contact_phone' => '+2557' . fake()->numerify('########'),
            'contact_email' => fake()->companyEmail(),
            'registration_document_path' => 'private/kyb_documents/' . fake()->uuid() . '.pdf',
            'status' => BusinessProfileStatus::Pending,
            'rejection_reason' => null,
        ];
    }
}
