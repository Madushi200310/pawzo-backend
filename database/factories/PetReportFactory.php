<?php

namespace Database\Factories;

use App\Models\Pet;
use App\Models\PetReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PetReport>
 */
class PetReportFactory extends Factory
{
    protected $model = PetReport::class;

    public function definition(): array
    {
        return [
            'pet_id'        => Pet::factory(),
            'reporter_id'   => User::factory(),
            'reason'        => fake()->randomElement(PetReport::REASONS),
            'notes'         => fake()->optional()->sentence(),
            'status'        => 'pending',
            'reviewed_by'   => null,
            'reviewed_at'   => null,
            'review_notes'  => null,
        ];
    }
}