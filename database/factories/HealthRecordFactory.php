<?php

namespace Database\Factories;

use App\Models\HealthRecord;
use App\Models\Pet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthRecord>
 */
class HealthRecordFactory extends Factory
{
    protected $model = HealthRecord::class;

    public function definition(): array
    {
        return [
            'pet_id'          => Pet::factory(),
            'record_type'     => fake()->randomElement(HealthRecord::TYPES),
            'title'           => fake()->sentence(3),
            'description'     => fake()->sentence(),
            'date'            => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'vet_name'        => null,
            'clinic_name'     => null,
            'medication_name' => null,
            'dosage'          => null,
            'start_date'      => null,
            'end_date'        => null,
            'is_ongoing'      => false,
        ];
    }
}