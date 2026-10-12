<?php

namespace Database\Factories;

use App\Models\Pet;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    protected $model = Reminder::class;

    public function definition(): array
    {
        return [
            'pet_id'      => Pet::factory(),
            'user_id'     => User::factory(),
            'type'        => fake()->randomElement(Reminder::TYPES),
            'title'       => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'due_date'    => fake()->dateTimeBetween('now', '+60 days')->format('Y-m-d'),
            'remind_at'   => null,
            'source_type' => null,
            'source_id'   => null,
            'is_completed' => false,
            'completed_at' => null,
            'is_dismissed' => false,
            'notified_at'  => null,
        ];
    }

    /**
     * State: reminder is overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'due_date' => now()->subDays(5)->toDateString(),
        ]);
    }

    /**
     * State: reminder is completed.
     */
    public function completed(): static
    {
        return $this->state(fn () => [
            'is_completed' => true,
            'completed_at' => now(),
        ]);
    }

    /**
     * State: reminder is dismissed.
     */
    public function dismissed(): static
    {
        return $this->state(fn () => [
            'is_dismissed' => true,
        ]);
    }
}