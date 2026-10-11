<?php

namespace Database\Factories;

use App\Models\Pet;
use App\Models\Vaccination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vaccination>
 */
class VaccinationFactory extends Factory
{
    protected $model = Vaccination::class;

    public function definition(): array
    {
        $given    = fake()->dateTimeBetween('-2 years', '-1 month');
        $nextDue  = (clone $given)->modify('+1 year');

        return [
            'pet_id'        => Pet::factory(),
            'vaccine_name'  => fake()->randomElement(['Rabies', 'DHPP', 'FVRCP', 'Bordetella', 'Leptospirosis']),
            'given_date'    => $given->format('Y-m-d'),
            'next_due_date' => $nextDue->format('Y-m-d'),
            'vet_name'      => fake()->name(),
            'batch_number'  => strtoupper(fake()->bothify('??-####-###')),
            'notes'         => fake()->optional()->sentence(),
        ];
    }
}