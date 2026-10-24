<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Complaint;
use App\Models\MarketplaceOrder;
use App\Models\PetService;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ServiceBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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

        if (is_file(__DIR__.'/../../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear before testing.'
            );
        }

        parent::setUp();

        Carbon::setTestNow(
            Carbon::parse('2026-10-20T12:00:00Z')
        );

        $this->admin = $this->user(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function service(): PetService
    {
        $type = BusinessType::create([
            'name' => 'Test Groomer',
            'slug' => 'dashboard-groomer',
        ]);

        $business = Business::create([
            'user_id' => $this->admin->id,
            'business_type_id' => $type->id,
            'name' => 'Dashboard Business',
            'slug' => 'dashboard-business',
            'status' => 'approved',
        ]);

        $service = new PetService([
            'business_id' => $business->id,
            'name' => 'Dashboard Grooming',
            'slug' => 'dashboard-grooming',
            'service_type' => 'grooming',
            'description' => 'Dashboard test service.',
            'price_from' => 1000,
            'currency' => 'LKR',
            'location_name' => 'Nittambuwa',
            'latitude' => 7.142,
            'longitude' => 80.096,
            'contact_phone' => '+94700000000',
            'status' => 'pending',
        ]);

        $service->user()->associate($this->admin);
        $service->save();

        return $service;
    }

    public function test_guests_and_regular_users_cannot_access_dashboard(): void
    {
        foreach (['/api/admin/dashboard', '/api/admin/dashboard/stats'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }

        Sanctum::actingAs($this->user());

        foreach (['/api/admin/dashboard', '/api/admin/dashboard/stats'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_inactive_and_unverified_admins_are_blocked(): void
    {
        foreach ([
            ['is_active' => false],
            ['email_verified_at' => null],
        ] as $attributes) {
            Sanctum::actingAs(
                $this->user(array_merge(['role' => 'admin'], $attributes))
            );

            $this->getJson('/api/admin/dashboard')->assertForbidden();
            $this->getJson('/api/admin/dashboard/stats')->assertForbidden();
        }
    }

    public function test_empty_dashboard_preserves_existing_and_new_sections(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'summary',
                'users',
                'businesses',
                'products',
                'pet_sales',
                'reviews',
                'chatbot',
                'recent_activity',
                'pets',
                'lost_found',
                'stray_reports',
                'services',
                'rentals',
                'bookings',
                'orders',
                'reports',
                'trends',
            ])
            ->assertJsonPath('summary.total_users', 1)
            ->assertJsonPath('summary.total_service_bookings', 0)
            ->assertJsonPath('summary.total_marketplace_orders', 0)
            ->assertJsonPath('reports.complaints.pending', 0)
            ->assertJsonPath('orders.completed_product_units', 0)
            ->assertJsonCount(30, 'trends.users_created');
    }

    public function test_services_bookings_and_complaints_are_counted(): void
    {
        $service = $this->service();

        ServiceBooking::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $this->admin->id,
            'pet_service_id' => $service->id,
            'scheduled_at' => now()->subHours(2),
            'status' => 'completed',
            'completed_at' => now()->subHour(),
        ]);

        ServiceBooking::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $this->admin->id,
            'pet_service_id' => $service->id,
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        Complaint::create([
            'reporter_id' => $this->admin->id,
            'target_type' => 'service',
            'target_id' => $service->id,
            'reason' => 'service_complaint',
            'description' => 'Please investigate this service.',
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.total_services', 1)
            ->assertJsonPath('summary.total_service_bookings', 2)
            ->assertJsonPath('summary.total_complaints', 1)
            ->assertJsonPath('services.pending', 1)
            ->assertJsonPath('bookings.completed', 1)
            ->assertJsonPath('bookings.pending', 1)
            ->assertJsonPath('reports.complaints.pending', 1)
            ->assertJsonPath(
                'bookings.most_used_services.0.service_id',
                $service->id,
            )
            ->assertJsonPath(
                'bookings.most_used_services.0.completed_bookings',
                1,
            );

        $this->getJson('/api/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('pending_by_module.services', 1)
            ->assertJsonPath('summary.pending_approvals', 1);
    }

    public function test_order_value_uses_only_completed_uncancelled_orders(): void
    {
        $category = ProductCategory::create([
            'name' => 'Dashboard Category',
            'slug' => 'dashboard-category',
        ]);

        $product = Product::create([
            'user_id' => $this->admin->id,
            'product_category_id' => $category->id,
            'name' => 'Dashboard Product',
            'price' => '1250.25',
            'quantity' => 10,
            'is_approved' => true,
            'is_active' => true,
        ]);

        foreach (['completed', 'pending', 'cancelled'] as $status) {
            MarketplaceOrder::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $this->admin->id,
                'seller_id' => $this->admin->id,
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => '1250.25',
                'total_price' => '2500.50',
                'currency' => 'LKR',
                'status' => $status,
                'completed_at' => $status === 'completed' ? now() : null,
                'cancelled_at' => $status === 'cancelled' ? now() : null,
            ]);
        }

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('orders.total', 3)
            ->assertJsonPath('orders.completed', 1)
            ->assertJsonPath('orders.pending', 1)
            ->assertJsonPath('orders.cancelled', 1)
            ->assertJsonPath('orders.completed_product_units', 2)
            ->assertJsonPath(
                'orders.completed_order_value_by_currency.0.currency',
                'LKR',
            )
            ->assertJsonPath(
                'orders.completed_order_value_by_currency.0.value',
                '2500.50',
            );
    }

    public function test_daily_trends_include_zero_days_and_exclude_old_users(): void
    {
        $this->user();

        $this->user([
            'created_at' => now()->subDays(40),
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.total_users', 3)
            ->assertJsonPath('trends.from', '2026-09-21')
            ->assertJsonPath('trends.to', '2026-10-20');

        $days = $response->json('trends.users_created');

        $this->assertCount(30, $days);
        $this->assertSame('2026-09-21', $days[0]['date']);
        $this->assertSame(0, $days[0]['count']);
        $this->assertSame('2026-10-20', $days[29]['date']);
        $this->assertSame(2, $days[29]['count']);
        $this->assertSame(2, array_sum(array_column($days, 'count')));
    }
}