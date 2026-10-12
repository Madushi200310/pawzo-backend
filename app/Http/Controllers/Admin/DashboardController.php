<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\PetSaleListing;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Unified admin dashboard — all key metrics in one request.
     */
    public function index(Request $request)
    {
        return response()->json([
            'summary' => $this->summary(),
            'users'   => $this->userStats(),
            'businesses' => $this->businessStats(),
            'products'   => $this->productStats(),
            'pet_sales'  => $this->petSaleStats(),
            'reviews'    => $this->reviewStats(),
            'chatbot'    => $this->chatbotStats(),
            'recent_activity' => $this->recentActivity(),
        ]);
    }

    /**
     * Top-level overview (quick glance numbers).
     */
    protected function summary(): array
    {
        return [
            'total_users'         => User::count(),
            'total_businesses'    => Business::count(),
            'total_products'      => Product::count(),
            'total_pet_sales'     => PetSaleListing::count(),
            'total_reviews'       => Review::count(),
            'total_conversations' => ChatbotConversation::count(),
            'total_messages'      => ChatbotMessage::count(),
            'pending_approvals'   => $this->totalPendingApprovals(),
        ];
    }

    /**
     * Pending count across all approval queues.
     */
    protected function totalPendingApprovals(): int
    {
        return Business::where('status', 'pending')->count()
            + Product::where('is_approved', false)->whereNull('rejection_reason')->count()
            + PetSaleListing::where('is_approved', false)->whereNull('rejection_reason')->count()
            + Review::where('is_approved', false)->whereNull('rejection_reason')->count();
    }

    /**
     * User statistics.
     */
    protected function userStats(): array
    {
        return [
            'total'         => User::count(),
            'admins'        => User::where('role', 'admin')->count(),
            'regular_users' => User::where('role', 'user')->count(),
            'active'        => User::where('is_active', true)->count(),
            'inactive'      => User::where('is_active', false)->count(),
            'new_this_week' => User::where('created_at', '>=', now()->subWeek())->count(),
            'new_this_month'=> User::where('created_at', '>=', now()->subMonth())->count(),
        ];
    }

    /**
     * Business stats.
     */
    protected function businessStats(): array
    {
        return [
            'total'      => Business::count(),
            'pending'    => Business::where('status', 'pending')->count(),
            'approved'   => Business::where('status', 'approved')->count(),
            'rejected'   => Business::where('status', 'rejected')->count(),
            'by_type'    => BusinessType::withCount('businesses')
                ->get(['id', 'name'])
                ->map(fn ($t) => [
                    'id'    => $t->id,
                    'name'  => $t->name,
                    'count' => $t->businesses_count,
                ]),
        ];
    }

    /**
     * Product stats.
     */
    protected function productStats(): array
    {
        return [
            'total'         => Product::count(),
            'pending'       => Product::where('is_approved', false)->whereNull('rejection_reason')->count(),
            'approved'      => Product::where('is_approved', true)->count(),
            'rejected'      => Product::whereNotNull('rejection_reason')->count(),
            'out_of_stock'  => Product::where('quantity', 0)->count(),
            'total_value'   => round(Product::selectRaw('SUM(price * quantity) as total')->value('total') ?? 0, 2),
            'by_category'   => ProductCategory::withCount('products')
                ->get(['id', 'name'])
                ->map(fn ($c) => [
                    'id'    => $c->id,
                    'name'  => $c->name,
                    'count' => $c->products_count,
                ]),
        ];
    }

    /**
     * Pet sale stats.
     */
    protected function petSaleStats(): array
    {
        return [
            'total'      => PetSaleListing::count(),
            'pending'    => PetSaleListing::where('is_approved', false)->whereNull('rejection_reason')->count(),
            'approved'   => PetSaleListing::where('is_approved', true)->count(),
            'rejected'   => PetSaleListing::whereNotNull('rejection_reason')->count(),
            'available'  => PetSaleListing::where('status', 'available')->count(),
            'reserved'   => PetSaleListing::where('status', 'reserved')->count(),
            'sold'       => PetSaleListing::where('status', 'sold')->count(),
        ];
    }

    /**
     * Review stats.
     */
    protected function reviewStats(): array
    {
        return [
            'total'          => Review::count(),
            'pending'        => Review::where('is_approved', false)->whereNull('rejection_reason')->count(),
            'approved'       => Review::where('is_approved', true)->count(),
            'rejected'       => Review::whereNotNull('rejection_reason')->count(),
            'average_rating' => round(Review::where('is_approved', true)->avg('rating') ?? 0, 2),
            'by_rating' => [
                '5' => Review::where('rating', 5)->where('is_approved', true)->count(),
                '4' => Review::where('rating', 4)->where('is_approved', true)->count(),
                '3' => Review::where('rating', 3)->where('is_approved', true)->count(),
                '2' => Review::where('rating', 2)->where('is_approved', true)->count(),
                '1' => Review::where('rating', 1)->where('is_approved', true)->count(),
            ],
        ];
    }

    /**
     * Chatbot stats.
     */
    protected function chatbotStats(): array
    {
        return [
            'total_conversations' => ChatbotConversation::count(),
            'guest_conversations' => ChatbotConversation::whereNull('user_id')->count(),
            'user_conversations'  => ChatbotConversation::whereNotNull('user_id')->count(),
            'total_messages'      => ChatbotMessage::count(),
            'by_source' => [
                'rule'     => ChatbotMessage::where('source', 'rule')->count(),
                'openai'   => ChatbotMessage::where('source', 'openai')->count(),
                'fallback' => ChatbotMessage::where('source', 'fallback')->count(),
            ],
            'messages_today' => ChatbotMessage::whereDate('created_at', today())->count(),
        ];
    }

    /**
     * Recent activity across the platform.
     */
    protected function recentActivity(): array
    {
        return [
            'latest_users' => User::latest()
                ->limit(5)
                ->get(['id', 'name', 'email', 'role', 'created_at']),

            'latest_businesses' => Business::with('businessType:id,name')
                ->latest()
                ->limit(5)
                ->get(['id', 'name', 'status', 'business_type_id', 'created_at']),

            'latest_products' => Product::with('category:id,name')
                ->latest()
                ->limit(5)
                ->get(['id', 'name', 'is_approved', 'product_category_id', 'created_at']),

            'latest_pet_sales' => PetSaleListing::latest()
                ->limit(5)
                ->get(['id', 'pet_type', 'breed', 'status', 'is_approved', 'created_at']),

            'latest_reviews' => Review::with('user:id,name')
                ->latest()
                ->limit(5)
                ->get(['id', 'rating', 'comment', 'is_approved', 'user_id', 'created_at']),

            'latest_conversations' => ChatbotConversation::latest('last_message_at')
                ->limit(5)
                ->get(['id', 'title', 'user_id', 'message_count', 'last_message_at']),
        ];
    }

    /**
     * Lightweight stats-only endpoint (for periodic refresh).
     */
    public function stats()
    {
        return response()->json([
            'summary' => $this->summary(),
            'pending_by_module' => [
                'businesses'   => Business::where('status', 'pending')->count(),
                'products'     => Product::where('is_approved', false)->whereNull('rejection_reason')->count(),
                'pet_sales'    => PetSaleListing::where('is_approved', false)->whereNull('rejection_reason')->count(),
                'reviews'      => Review::where('is_approved', false)->whereNull('rejection_reason')->count(),
            ],
        ]);
    }
}