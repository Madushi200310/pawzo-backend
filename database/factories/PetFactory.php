<?php

namespace Database\Factories;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pet>
 */
class PetFactory extends Factory
{
    protected $model = Pet::class;

    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'name'            => fake()->firstName(),
            'type'            => fake()->randomElement(['Dog', 'Cat', 'Bird', 'Rabbit']),
            'breed'           => fake()->word(),
            'gender'          => fake()->randomElement(['male', 'female', 'unknown']),
            'date_of_birth'   => fake()->dateTimeBetween('-10 years', 'now')->format('Y-m-d'),
            'color'           => fake()->safeColorName(),
            'weight'          => fake()->randomFloat(2, 0.5, 60),
            'characteristics' => fake()->sentence(),
        ];
    }
}