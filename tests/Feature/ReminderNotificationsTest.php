<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Pet;
use App\Models\Reminder;
use App\Models\User;
use App\Services\ReminderNotificationService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use SQLite :memory: for reminder notification tests.'
            );
        }

        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear first.'
            );
        }

        parent::setUp();

        $this->travelTo(
            \Illuminate\Support\Carbon::parse('2026-10-16 12:00:00')
        );
    }

    private function owner(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function reminder(
        array $attributes = [],
        ?User $owner = null,
    ): Reminder {
        $owner ??= $this->owner();

        $pet = Pet::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Buddy',
        ]);

        return Reminder::factory()->create(array_replace([
            'user_id' => $owner->id,
            'pet_id' => $pet->id,
            'type' => 'vaccination',
            'title' => 'Vaccination due',
            'description' => 'Please arrange a vet visit.',
            'due_date' => now()->toDateString(),
            'remind_at' => null,
            'is_completed' => false,
            'is_dismissed' => false,
            'notified_at' => null,
        ], $attributes));
    }

    public function test_due_reminder_creates_notification_only_once(): void
    {
        $reminder = $this->reminder();

        $service = app(ReminderNotificationService::class);

        $this->assertSame(1, $service->sendDue());

        $notification = Notification::sole();

        $this->assertSame(
            (int) $reminder->user_id,
            (int) $notification->user_id
        );
        $this->assertSame(
            Notification::TYPE_VACCINATION_REMINDER,
            $notification->type
        );
        $this->assertSame(
            (int) $reminder->id,
            $notification->data['reminder_id']
        );
        $this->assertSame(
            "/api/reminders/{$reminder->id}",
            $notification->action_url
        );
        $this->assertNotNull($reminder->fresh()->notified_at);

        $this->assertSame(0, $service->sendDue());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_explicit_remind_at_controls_delivery_time(): void
    {
        $future = $this->reminder([
            'due_date' => now()->toDateString(),
            'remind_at' => now()->addHour(),
        ]);

        $early = $this->reminder([
            'due_date' => now()->addDays(5)->toDateString(),
            'remind_at' => now()->subMinute(),
        ]);

        $laterDate = $this->reminder([
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $this->assertSame(
            1,
            app(ReminderNotificationService::class)->sendDue()
        );

        $this->assertNull($future->fresh()->notified_at);
        $this->assertNull($laterDate->fresh()->notified_at);
        $this->assertNotNull($early->fresh()->notified_at);

        $this->assertSame(
            (int) $early->id,
            Notification::sole()->data['reminder_id']
        );
    }

    public function test_completed_dismissed_and_already_notified_reminders_are_skipped(): void
    {
        $this->reminder(['is_completed' => true]);
        $this->reminder(['is_dismissed' => true]);
        $this->reminder(['notified_at' => now()->subHour()]);

        $this->assertSame(
            0,
            app(ReminderNotificationService::class)->sendDue()
        );

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_ineligible_accounts_and_mismatched_pet_owners_are_skipped(): void
    {
        $inactive = $this->owner(['is_active' => false]);
        $unverified = $this->owner(['email_verified_at' => null]);

        $this->reminder(owner: $inactive);
        $this->reminder(owner: $unverified);

        $mismatched = $this->reminder();

        $mismatched->update([
            'user_id' => $this->owner()->id,
        ]);

        $this->assertSame(
            0,
            app(ReminderNotificationService::class)->sendDue()
        );

        $this->assertDatabaseCount('notifications', 0);
        $this->assertNull($mismatched->fresh()->notified_at);
    }

    public function test_medication_course_end_text_is_preserved(): void
    {
        $reminder = $this->reminder([
            'type' => 'medication',
            'title' => 'Medication course ends',
            'description' => 'The prescribed course ends today.',
        ]);

        $this->assertSame(
            1,
            app(ReminderNotificationService::class)->sendDue()
        );

        $notification = Notification::sole();

        $this->assertSame(
            Notification::TYPE_MEDICINE_REMINDER,
            $notification->type
        );
        $this->assertSame($reminder->title, $notification->title);
        $this->assertSame(
            $reminder->description,
            $notification->body
        );
    }

    public function test_other_health_reminder_types_use_generic_health_notification(): void
    {
        foreach ([
            'deworming',
            'vet_appointment',
            'grooming',
        ] as $type) {
            $this->reminder(['type' => $type]);
        }

        $this->assertSame(
            3,
            app(ReminderNotificationService::class)->sendDue()
        );

        $this->assertSame(
            3,
            Notification::where(
                'type',
                Notification::TYPE_HEALTH_REMINDER
            )->count()
        );
    }

    public function test_notification_failure_does_not_mark_reminder_as_notified(): void
    {
        $reminder = $this->reminder();

        $this->mock(
            NotificationService::class,
            function ($mock) {
                $mock->shouldReceive('send')
                    ->once()
                    ->andThrow(
                        new \RuntimeException(
                            'Simulated reminder notification failure'
                        )
                    );
            }
        );

        $caught = false;

        try {
            app(ReminderNotificationService::class)->sendDue();
        } catch (\RuntimeException $error) {
            $caught = true;

            $this->assertSame(
                'Simulated reminder notification failure',
                $error->getMessage()
            );
        }

        $this->assertTrue($caught);
        $this->assertNull($reminder->fresh()->notified_at);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_command_dispatches_due_reminders(): void
    {
        $reminder = $this->reminder();

        $this->artisan('reminders:notify')
            ->expectsOutput('Created 1 reminder notification(s).')
            ->assertExitCode(0);

        $this->assertNotNull($reminder->fresh()->notified_at);
        $this->assertDatabaseCount('notifications', 1);
    }
}