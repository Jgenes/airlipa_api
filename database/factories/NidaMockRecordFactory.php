<?php

namespace Database\Factories;

use App\Models\NidaMockRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NidaMockRecord>
 */
class NidaMockRecordFactory extends Factory
{
    protected $model = NidaMockRecord::class;

    public function definition(): array
    {
        return [
            'nida_number' => fake()->unique()->numerify('199#########000#####'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->date('Y-m-d', '-20 years'),
            'gender' => fake()->randomElement(['Male', 'Female']),
            'place_of_birth' => fake()->city(),
            'mother_first_name' => fake()->firstName('female'),
            'region' => 'Dar es Salaam',
            'district' => 'Ilala',
            'ward' => 'Kariakoo',
        ];
    }
}
