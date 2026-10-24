<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceOrder;
use App\Models\PetSaleListing;
use App\Models\Product;
use App\Models\ServiceBooking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminTransactionController extends Controller
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

    private function isBooking(Request $request): bool
    {
        $kind = $request->route('kind');

        abort_unless(in_array($kind, ['booking', 'order'], true), 404);

        return $kind === 'booking';
    }

    private function query(Request $request): Builder
    {
        return $this->isBooking($request)
            ? ServiceBooking::query()
            : MarketplaceOrder::query();
    }

    private function relations(Request $request): array
    {
        if ($this->isBooking($request)) {
            return [
                'user:id,name,email',
                'service:id,name,user_id,business_id',
                'service.user:id,name,email',
            ];
        }

        return [
            'user:id,name,email',
            'seller:id,name,email',
            'product:id,name',
            'petSaleListing:id,pet_type,breed',
        ];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'status' => [$message],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $booking = $this->isBooking($request);

        $data = $request->validate([
            'status' => [
                'sometimes',
                'required',
                Rule::in(ServiceBooking::STATUSES),
            ],
            'reference' => ['sometimes', 'required', 'uuid'],
            'user_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'seller_id' => [
                Rule::prohibitedIf($booking),
                'sometimes',
                'required',
                'integer',
                'min:1',
            ],
            'pet_service_id' => [
                Rule::prohibitedIf(! $booking),
                'sometimes',
                'required',
                'integer',
                'min:1',
            ],
            'type' => [
                Rule::prohibitedIf($booking),
                'sometimes',
                'required',
                Rule::in(['product', 'pet-sale']),
            ],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $query = $this->query($request)
            ->with($this->relations($request));

        foreach (
            ['status', 'reference', 'user_id', 'seller_id', 'pet_service_id']
            as $field
        ) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        if (isset($data['type'])) {
            $column = $data['type'] === 'product'
                ? 'product_id'
                : 'pet_sale_listing_id';

            $query->whereNotNull($column);
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $record = $this->query($request)
            ->with($this->relations($request))
            ->findOrFail($id);

        return response()->json(['data' => $record]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $reason = trim($data['reason']);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A cancellation reason is required.'],
            ]);
        }

        $record = DB::transaction(function () use (
            $request,
            $id,
            $reason,
        ) {
            $record = $this->query($request)
                ->lockForUpdate()
                ->findOrFail($id);

            // Repeated cancellation must not restore stock again.
            if ($record->status === 'cancelled') {
                return $record;
            }

            if (! in_array($record->status, ['pending', 'accepted'], true)) {
                $this->invalid(
                    'Only pending or accepted transactions can be cancelled.'
                );
            }

            if (
                $record instanceof MarketplaceOrder
                && $record->status === 'accepted'
            ) {
                $this->restoreStock($record);
            }

            $record->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by_admin_id' => $request->user()->id,
                'admin_cancellation_reason' => $reason,
            ])->save();

            return $record;
        });

        return response()->json([
            'message' => 'Transaction cancelled.',
            'data' => $record->load($this->relations($request)),
        ]);
    }

    private function restoreStock(MarketplaceOrder $order): void
    {
        if (
            ($order->product_id === null)
            === ($order->pet_sale_listing_id === null)
        ) {
            $this->invalid('This order has an invalid item reference.');
        }

        if ($order->product_id !== null) {
            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($order->product_id);

            $restoredQuantity = $product->quantity + $order->quantity;

            if (
                $order->quantity < 1
                || $restoredQuantity > 2147483647
            ) {
                $this->invalid('The product stock cannot be restored.');
            }

            $product->quantity = $restoredQuantity;
            $product->save();

            return;
        }

        $listing = PetSaleListing::query()
            ->lockForUpdate()
            ->findOrFail($order->pet_sale_listing_id);

        if ($listing->status !== 'reserved') {
            $this->invalid(
                'The pet listing is no longer reserved. Investigate before cancelling.'
            );
        }

        $listing->status = 'available';
        $listing->save();
    }
}