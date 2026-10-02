<?php

namespace Tests\Feature\Pet;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PetNameGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_20_names_for_male_dog_cute_style(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/pet-names/generate', [
                'type'   => 'Dog',
                'gender' => 'male',
                'color'  => 'Brown',
                'style'  => 'Cute',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', '20 pet names generated successfully.')
            ->assertJsonPath('count', 20)
            ->assertJsonCount(20, 'names');

        // All names must be strings
        foreach ($response->json('names') as $name) {
            $this->assertIsString($name);
            $this->assertNotEmpty($name);
        }
    }

    public function test_generates_20_names_for_female_cat_royal_style(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pet-names/generate', [
                'type'   => 'Cat',
                'gender' => 'female',
                'color'  => 'White',
                'style'  => 'Royal',
            ])
            ->assertOk()
            ->assertJsonPath('count', 20);
    }

    public function test_generates_20_names_for_funny_style(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pet-names/generate', [
                'type'   => 'Dog',
                'gender' => 'unknown',
                'color'  => null,
                'style'  => 'Funny',
            ])
            ->assertOk()
            ->assertJsonPath('count', 20);
    }

    public function test_generates_20_names_for_sinhala_style(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pet-names/generate', [
                'type'   => 'Dog',
                'gender' => 'male',
                'style'  => 'Sinhala',
            ])
            ->assertOk()
            ->assertJsonPath('count', 20);
    }

    public function test_generates_20_names_for_food_inspired_style(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pet-names/generate', [
                'type'   => 'Dog',
                'gender' => 'female',
                'style'  => 'Food-inspired',
            ])
            ->assertOk()
            ->assertJsonPath('count', 20);
    }

    public function test_type_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pet-names/generate', [
                'gender' => 'male',
                'style'  => 'Cute',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_gender_is_required_and_valid(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pet-names/generate', [
                'type'   => 'Dog',
                'gender' => 'invalid',
                'style'  => 'Cute',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gender']);
    }

    public function test_style_must_be_one_of_the_allowed_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pet-names/generate', [
                'type'   => 'Dog',
                'gender' => 'male',
                'style'  => 'Weird',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['style']);
    }

    public function test_guest_cannot_generate_names(): void
    {
        $this->postJson('/api/pet-names/generate', [
            'type'   => 'Dog',
            'gender' => 'male',
            'style'  => 'Cute',
        ])->assertUnauthorized();
    }

    public function test_names_are_unique_within_one_response(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/pet-names/generate', [
                'type'   => 'Dog',
                'gender' => 'male',
                'style'  => 'Cool',
            ]);

        $names = $response->json('names');
        $this->assertSame(count($names), count(array_unique($names)), 'Generated names must be unique.');
    }
}