<?php

namespace Database\Factories;

use App\Models\HealthShareToken;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HealthShareToken>
 */
class HealthShareTokenFactory extends Factory
{
    protected $model = HealthShareToken::class;

    public function definition(): array
    {
        return [
            'pet_id'         => Pet::factory(),
            'user_id'        => User::factory(),
            'token'          => Str::random(64),
            'include'        => ['pet_details' => true],
            'expires_at'     => null,
            'view_count'     => 0,
            'last_viewed_at' => null,
        ];
    }
}