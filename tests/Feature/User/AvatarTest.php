<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_user_can_upload_avatar(): void
    {
        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('me.jpg', 500, 500);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/avatar', [
                'avatar' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Avatar uploaded successfully.');

        $user->refresh();
        $this->assertNotNull($user->avatar);
        Storage::disk('public')->assertExists($user->avatar);
    }

    public function test_uploading_new_avatar_deletes_old_one(): void
    {
        $user = User::factory()->create();

        // First upload
        $first = UploadedFile::fake()->image('first.jpg');
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/avatar', ['avatar' => $first])
            ->assertOk();

        $oldPath = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($oldPath);

        // Second upload
        $second = UploadedFile::fake()->image('second.png');
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/avatar', ['avatar' => $second])
            ->assertOk();

        $newPath = $user->fresh()->avatar;

        // Old gone, new exists
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_avatar_must_be_image(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/avatar', ['avatar' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_avatar_rejects_wrong_image_extension(): void
    {
        $user = User::factory()->create();
        // .gif not allowed per spec
        $file = UploadedFile::fake()->image('animated.gif');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/avatar', ['avatar' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_avatar_max_size_is_2mb(): void
    {
        $user = User::factory()->create();
        // 3 MB file
        $file = UploadedFile::fake()->image('big.jpg')->size(3000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/avatar', ['avatar' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_user_can_delete_avatar(): void
    {
        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('me.jpg');
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/avatar', ['avatar' => $file])
            ->assertOk();

        $path = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/profile/avatar')
            ->assertOk()
            ->assertJsonPath('message', 'Avatar removed successfully.');

        $this->assertNull($user->fresh()->avatar);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_avatar_when_none_exists_fails(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/profile/avatar')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_guest_cannot_upload_avatar(): void
    {
        $file = UploadedFile::fake()->image('me.jpg');

        $this->postJson('/api/profile/avatar', ['avatar' => $file])
            ->assertUnauthorized();
    }
}