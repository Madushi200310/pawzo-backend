<?php

namespace Tests\Feature;

use App\Models\HealthRecord;
use App\Models\MedicationSchedule;
use App\Models\Notification;
use App\Models\Pet;
use App\Models\User;
use App\Services\MedicationNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicationSchedulesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Pet $pet;
    private HealthRecord $record;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use a test configuration with SQLite :memory:.'
            );
        }

        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear before testing.'
            );
        }

        parent::setUp();

        // 07:30 in Asia/Colombo.
        Carbon::setTestNow(Carbon::parse('2026-10-20T02:00:00Z'));

        $this->owner = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->pet = Pet::factory()->create([
            'user_id' => $this->owner->id,
            'name' => 'Buddy',
            'type' => 'Dog',
        ]);

        $this->record = HealthRecord::create([
            'pet_id' => $this->pet->id,
            'record_type' => 'medication',
            'title' => 'Medication course',
            'date' => '2026-10-20',
            'medication_name' => 'Test medicine',
            'dosage' => 'As prescribed',
            'start_date' => '2026-10-20',
            'end_date' => '2026-10-22',
            'is_ongoing' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(): string
    {
        return "/api/health-records/{$this->record->id}/medication-schedule";
    }

    private function createSchedule(): MedicationSchedule
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson($this->url(), [
            'timezone' => 'Asia/Colombo',
            'times' => ['20:00', '08:00'],
        ])->assertCreated();

        return MedicationSchedule::findOrFail(
            $response->json('data.id')
        );
    }

    private function sendDue(): int
    {
        return app(MedicationNotificationService::class)->sendDue();
    }

    public function test_guests_and_other_owners_cannot_manage_schedule(): void
    {
        $this->getJson($this->url())->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]));

        $this->postJson($this->url(), [
            'timezone' => 'Asia/Colombo',
            'times' => ['08:00'],
        ])->assertForbidden();

        $this->assertDatabaseCount('medication_schedules', 0);
    }

    public function test_schedule_stores_sorted_times_and_next_due_in_utc(): void
    {
        $schedule = $this->createSchedule();

        $this->assertSame(['08:00', '20:00'], $schedule->times);

        $this->assertSame(
            '2026-10-20 02:30:00',
            $schedule->next_due_at->copy()->utc()->format('Y-m-d H:i:s'),
        );

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.id', $schedule->id);

        $this->assertSame(0, $this->sendDue());
    }

    public function test_each_daily_dose_notifies_once_then_advances(): void
    {
        $schedule = $this->createSchedule();

        Carbon::setTestNow(Carbon::parse('2026-10-20T02:30:00Z'));

        $this->assertSame(1, $this->sendDue());
        $this->assertSame(0, $this->sendDue());

        $notification = Notification::sole();

        $this->assertSame(
            Notification::TYPE_MEDICINE_REMINDER,
            $notification->type,
        );

        $this->assertSame(
            $this->record->id,
            $notification->data['health_record_id'],
        );

        $this->assertSame(
            '2026-10-20 14:30:00',
            $schedule->fresh()->next_due_at
                ->copy()->utc()->format('Y-m-d H:i:s'),
        );

        Carbon::setTestNow(Carbon::parse('2026-10-20T14:30:00Z'));

        $this->assertSame(1, $this->sendDue());
        $this->assertSame(0, $this->sendDue());

        $this->assertSame(
            '2026-10-21 02:30:00',
            $schedule->fresh()->next_due_at
                ->copy()->utc()->format('Y-m-d H:i:s'),
        );

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_pause_and_resume_skip_paused_doses(): void
    {
        $schedule = $this->createSchedule();

        $this->patchJson($this->url(), [
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.next_due_at', null);

        Carbon::setTestNow(Carbon::parse('2026-10-20T03:00:00Z'));

        $this->assertSame(0, $this->sendDue());

        $this->patchJson($this->url(), [
            'is_active' => true,
        ])->assertOk();

        $this->assertSame(
            '2026-10-20 14:30:00',
            $schedule->fresh()->next_due_at
                ->copy()->utc()->format('Y-m-d H:i:s'),
        );

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_last_dose_disables_schedule(): void
    {
        $this->record->update(['end_date' => '2026-10-20']);
        $schedule = $this->createSchedule();

        Carbon::setTestNow(Carbon::parse('2026-10-20T14:30:00Z'));

        $this->assertSame(1, $this->sendDue());

        $this->assertFalse($schedule->fresh()->is_active);
        $this->assertNull($schedule->fresh()->next_due_at);
        $this->assertSame(0, $this->sendDue());
    }

    public function test_expired_course_does_not_send_stale_notifications(): void
    {
        $schedule = $this->createSchedule();

        Carbon::setTestNow(Carbon::parse('2026-10-23T03:00:00Z'));

        $this->assertSame(0, $this->sendDue());
        $this->assertFalse($schedule->fresh()->is_active);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_stopped_medication_disables_notifications(): void
    {
        $schedule = $this->createSchedule();
        $this->record->update(['is_ongoing' => false]);

        Carbon::setTestNow(Carbon::parse('2026-10-20T02:30:00Z'));

        $this->assertSame(0, $this->sendDue());
        $this->assertFalse($schedule->fresh()->is_active);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_invalid_times_timezone_and_duplicate_schedule_are_blocked(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->url(), [
            'timezone' => 'Invalid/Timezone',
            'times' => ['25:00'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['timezone', 'times.0']);

        $this->postJson($this->url(), [
            'timezone' => 'Asia/Colombo',
            'times' => ['08:00', '08:00'],
        ])->assertUnprocessable();

        $this->createSchedule();

        $this->postJson($this->url(), [
            'timezone' => 'Asia/Colombo',
            'times' => ['09:00'],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('medication_schedules', 1);
    }

    public function test_deleted_pet_prevents_notifications(): void
    {
        $schedule = $this->createSchedule();
        $this->pet->delete();

        Carbon::setTestNow(Carbon::parse('2026-10-20T02:30:00Z'));

        $this->assertSame(0, $this->sendDue());
        $this->assertFalse($schedule->fresh()->is_active);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_owner_can_delete_schedule(): void
    {
        $this->createSchedule();

        $this->deleteJson($this->url())->assertOk();

        $this->assertDatabaseCount('medication_schedules', 0);
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}