<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\Complaint;
use App\Models\FoundPetReport;
use App\Models\LostPetReport;
use App\Models\MarketplaceOrder;
use App\Models\Pet;
use App\Models\PetMatch;
use App\Models\PetRental;
use App\Models\PetRentalRequest;
use App\Models\PetReport;
use App\Models\PetSaleListing;
use App\Models\PetService;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Review;
use App\Models\ServiceBooking;
use App\Models\StrayReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless($user, 401);

        abort_unless(
            $user->isAdmin()
                && $user->is_active
                && $user->email_verified_at !== null,
            403,
            'An active admin account with a verified email is required.',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'summary' => $this->summary(),
            'users' => $this->userStats(),
            'businesses' => $this->businessStats(),
            'products' => $this->productStats(),
            'pet_sales' => $this->petSaleStats(),
            'reviews' => $this->reviewStats(),
            'chatbot' => $this->chatbotStats(),
            'pets' => ['total' => Pet::count()],
            'lost_found' => $this->lostFoundStats(),
            'stray_reports' => $this->statusCounts(
                StrayReport::class,
                StrayReport::STATUSES,
            ),
            'services' => $this->statusCounts(
                PetService::class,
                PetService::STATUSES,
            ),
            'rentals' => [
                'listings' => $this->statusCounts(
                    PetRental::class,
                    PetRental::STATUSES,
                ),
                'requests' => $this->statusCounts(
                    PetRentalRequest::class,
                    PetRentalRequest::STATUSES,
                ),
            ],
            'bookings' => $this->bookingStats(),
            'orders' => $this->orderStats(),
            'reports' => [
                'complaints' => $this->statusCounts(
                    Complaint::class,
                    Complaint::STATUSES,
                ),
                'pet_profiles' => $this->statusCounts(
                    PetReport::class,
                    PetReport::STATUSES,
                ),
            ],
            'trends' => $this->trends(),
            'recent_activity' => $this->recentActivity(),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'summary' => $this->summary(),
            'pending_by_module' => $this->pendingByModule(),
        ]);
    }

    protected function summary(): array
    {
        return [
            'total_users' => User::count(),
            'total_businesses' => Business::count(),
            'total_products' => Product::count(),
            'total_pet_sales' => PetSaleListing::count(),
            'total_reviews' => Review::count(),
            'total_conversations' => ChatbotConversation::count(),
            'total_messages' => ChatbotMessage::count(),
            'pending_approvals' => array_sum($this->pendingByModule()),
            'total_pets' => Pet::count(),
            'total_lost_pets' => LostPetReport::count(),
            'total_found_pets' => FoundPetReport::count(),
            'total_possible_matches' => PetMatch::count(),
            'total_stray_reports' => StrayReport::count(),
            'total_services' => PetService::count(),
            'total_rental_listings' => PetRental::count(),
            'total_service_bookings' => ServiceBooking::count(),
            'total_marketplace_orders' => MarketplaceOrder::count(),
            'total_complaints' => Complaint::count(),
            'total_pet_profile_reports' => PetReport::count(),
        ];
    }

    private function pendingByModule(): array
    {
        return [
            'businesses' => Business::where('status', 'pending')->count(),
            'products' => Product::where('is_approved', false)
                ->whereNull('rejection_reason')->count(),
            'pet_sales' => PetSaleListing::where('is_approved', false)
                ->whereNull('rejection_reason')->count(),
            'reviews' => Review::where('is_approved', false)
                ->whereNull('rejection_reason')->count(),
            'services' => PetService::where('status', 'pending')->count(),
        ];
    }

    private function statusCounts(string $model, array $statuses): array
    {
        $counts = $model::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $result = ['total' => array_sum($counts)];

        foreach ($statuses as $status) {
            $result[$status] = $counts[$status] ?? 0;
        }

        return $result;
    }

    protected function userStats(): array
    {
        return [
            'total' => User::count(),
            'admins' => User::where('role', 'admin')->count(),
            'regular_users' => User::where('role', 'user')->count(),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'new_this_week' => User::where(
                'created_at',
                '>=',
                now()->subWeek(),
            )->count(),
            'new_this_month' => User::where(
                'created_at',
                '>=',
                now()->subMonth(),
            )->count(),
        ];
    }

    protected function businessStats(): array
    {
        return array_merge(
            $this->statusCounts(Business::class, [
                'pending', 'approved', 'rejected',
            ]),
            [
                'by_type' => BusinessType::withCount('businesses')
                    ->get(['id', 'name'])
                    ->map(fn ($type) => [
                        'id' => $type->id,
                        'name' => $type->name,
                        'count' => $type->businesses_count,
                    ]),
            ],
        );
    }

    protected function productStats(): array
    {
        return [
            'total' => Product::count(),
            'pending' => Product::where('is_approved', false)
                ->whereNull('rejection_reason')->count(),
            'approved' => Product::where('is_approved', true)->count(),
            'rejected' => Product::whereNotNull('rejection_reason')->count(),
            'out_of_stock' => Product::where('quantity', 0)->count(),
            'total_value' => round(
                (float) Product::selectRaw(
                    'SUM(price * quantity) AS total'
                )->value('total'),
                2,
            ),
            'by_category' => ProductCategory::withCount('products')
                ->get(['id', 'name'])
                ->map(fn ($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'count' => $category->products_count,
                ]),
        ];
    }

    protected function petSaleStats(): array
    {
        return array_merge(
            $this->statusCounts(PetSaleListing::class, [
                'available', 'reserved', 'sold',
            ]),
            [
                'pending' => PetSaleListing::where('is_approved', false)
                    ->whereNull('rejection_reason')->count(),
                'approved' => PetSaleListing::where(
                    'is_approved',
                    true,
                )->count(),
                'rejected' => PetSaleListing::whereNotNull(
                    'rejection_reason'
                )->count(),
            ],
        );
    }

    protected function reviewStats(): array
    {
        $ratings = Review::where('is_approved', true)
            ->selectRaw('rating, COUNT(*) AS aggregate')
            ->groupBy('rating')
            ->pluck('aggregate', 'rating');

        $byRating = [];

        foreach ([5, 4, 3, 2, 1] as $rating) {
            $byRating[(string) $rating] = (int) ($ratings[$rating] ?? 0);
        }

        return [
            'total' => Review::count(),
            'pending' => Review::where('is_approved', false)
                ->whereNull('rejection_reason')->count(),
            'approved' => Review::where('is_approved', true)->count(),
            'rejected' => Review::whereNotNull('rejection_reason')->count(),
            'average_rating' => round(
                (float) Review::where('is_approved', true)->avg('rating'),
                2,
            ),
            'by_rating' => $byRating,
        ];
    }

    protected function chatbotStats(): array
    {
        return [
            'total_conversations' => ChatbotConversation::count(),
            'guest_conversations' => ChatbotConversation::whereNull(
                'user_id'
            )->count(),
            'user_conversations' => ChatbotConversation::whereNotNull(
                'user_id'
            )->count(),
            'total_messages' => ChatbotMessage::count(),
            'by_source' => [
                'rule' => ChatbotMessage::where('source', 'rule')->count(),
                'openai' => ChatbotMessage::where('source', 'openai')->count(),
                'fallback' => ChatbotMessage::where(
                    'source',
                    'fallback',
                )->count(),
            ],
            'messages_today' => ChatbotMessage::whereDate(
                'created_at',
                today(),
            )->count(),
        ];
    }

    private function lostFoundStats(): array
    {
        return [
            'lost' => $this->statusCounts(
                LostPetReport::class,
                LostPetReport::STATUSES,
            ),
            'found' => $this->statusCounts(
                FoundPetReport::class,
                FoundPetReport::STATUSES,
            ),
            'possible_matches' => PetMatch::count(),
            'flagged_matches' => PetMatch::whereNotNull(
                'flagged_at'
            )->count(),
            // Report status is not proof that a particular match succeeded.
            'lost_reports_marked_reunited' => LostPetReport::where(
                'status',
                'reunited',
            )->count(),
        ];
    }

    private function bookingStats(): array
    {
        $topServices = ServiceBooking::query()
            ->selectRaw('pet_service_id, COUNT(*) AS completed_bookings')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->groupBy('pet_service_id')
            ->orderByDesc('completed_bookings')
            ->orderBy('pet_service_id')
            ->limit(10)
            ->get();

        $names = PetService::whereIn(
            'id',
            $topServices->pluck('pet_service_id'),
        )->pluck('name', 'id');

        return array_merge(
            $this->statusCounts(
                ServiceBooking::class,
                ServiceBooking::STATUSES,
            ),
            [
                'most_used_services' => $topServices->map(
                    fn ($row) => [
                        'service_id' => $row->pet_service_id,
                        'name' => $names[$row->pet_service_id] ?? null,
                        'completed_bookings' => (int) $row->completed_bookings,
                    ]
                ),
            ],
        );
    }

    private function orderStats(): array
    {
        $completed = MarketplaceOrder::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereNull('cancelled_at');

        return array_merge(
            $this->statusCounts(
                MarketplaceOrder::class,
                MarketplaceOrder::STATUSES,
            ),
            [
                'product_orders' => MarketplaceOrder::whereNotNull(
                    'product_id'
                )->count(),
                'pet_sale_orders' => MarketplaceOrder::whereNotNull(
                    'pet_sale_listing_id'
                )->count(),
                'completed_product_units' => (int) (clone $completed)
                    ->whereNotNull('product_id')->sum('quantity'),
                'completed_pet_sales' => (clone $completed)
                    ->whereNotNull('pet_sale_listing_id')->count(),
                'completed_order_value_by_currency' => (clone $completed)
                    ->selectRaw('currency, SUM(total_price) AS total')
                    ->groupBy('currency')
                    ->orderBy('currency')
                    ->get()
                    ->map(fn ($row) => [
                        'currency' => $row->currency,
                        'value' => number_format(
                            (float) $row->total,
                            2,
                            '.',
                            '',
                        ),
                    ]),
            ],
        );
    }

    private function dailyCounts(
        string $model,
        string $column = 'created_at',
        bool $completedOnly = false,
    ): array {
        $start = now()->subDays(29)->startOfDay();
        $end = now();

        $query = $model::query()
            ->whereBetween($column, [$start, $end]);

        if ($completedOnly) {
            $query->where('status', 'completed')
                ->whereNull('cancelled_at');
        }

        // Column names are internal constants, never request input.
        $counts = $query
            ->selectRaw("DATE({$column}) AS day, COUNT(*) AS aggregate")
            ->groupByRaw("DATE({$column})")
            ->pluck('aggregate', 'day');

        $result = [];

        for ($offset = 0; $offset < 30; $offset++) {
            $day = $start->copy()->addDays($offset)->toDateString();

            $result[] = [
                'date' => $day,
                'count' => (int) ($counts[$day] ?? 0),
            ];
        }

        return $result;
    }

    private function trends(): array
    {
        return [
            'timezone' => config('app.timezone', 'UTC'),
            'from' => now()->subDays(29)->toDateString(),
            'to' => now()->toDateString(),
            'users_created' => $this->dailyCounts(User::class),
            'businesses_created' => $this->dailyCounts(Business::class),
            'lost_reports_created' => $this->dailyCounts(
                LostPetReport::class
            ),
            'found_reports_created' => $this->dailyCounts(
                FoundPetReport::class
            ),
            'service_bookings_created' => $this->dailyCounts(
                ServiceBooking::class
            ),
            'orders_created' => $this->dailyCounts(
                MarketplaceOrder::class
            ),
            'orders_completed' => $this->dailyCounts(
                MarketplaceOrder::class,
                'completed_at',
                true,
            ),
            'complaints_created' => $this->dailyCounts(Complaint::class),
        ];
    }

    protected function recentActivity(): array
    {
        return [
            'latest_users' => User::latest()->limit(5)
                ->get(['id', 'name', 'email', 'role', 'created_at']),

            'latest_businesses' => Business::with('businessType:id,name')
                ->latest()->limit(5)
                ->get(['id', 'name', 'status', 'business_type_id', 'created_at']),

            'latest_products' => Product::with('category:id,name')
                ->latest()->limit(5)
                ->get(['id', 'name', 'is_approved', 'product_category_id', 'created_at']),

            'latest_pet_sales' => PetSaleListing::latest()->limit(5)
                ->get(['id', 'pet_type', 'breed', 'status', 'is_approved', 'created_at']),

            'latest_reviews' => Review::with('user:id,name')
                ->latest()->limit(5)
                ->get(['id', 'rating', 'comment', 'is_approved', 'user_id', 'created_at']),

            'latest_conversations' => ChatbotConversation::latest(
                'last_message_at'
            )->limit(5)
                ->get(['id', 'title', 'user_id', 'message_count', 'last_message_at']),

            'latest_bookings' => ServiceBooking::orderByDesc('id')->limit(5)
                ->get(['id', 'user_id', 'pet_service_id', 'status', 'created_at']),

            'latest_orders' => MarketplaceOrder::orderByDesc('id')->limit(5)
                ->get(['id', 'user_id', 'seller_id', 'status', 'total_price', 'currency', 'created_at']),

            'latest_complaints' => Complaint::orderByDesc('id')->limit(5)
                ->get(['id', 'target_type', 'target_id', 'reason', 'status', 'created_at']),
        ];
    }
}