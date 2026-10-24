<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceOrder;
use App\Models\PetSaleListing;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarketplaceOrderController extends Controller
{
    private function verifiedUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user, 401);

        abort_unless(
            $user->is_active && $user->email_verified_at !== null,
            403,
            'An active account with a verified email is required.',
        );

        return $user;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'order' => [$message],
        ]);
    }

    private function ensureSeller(User $seller): void
    {
        if (
            ! $seller->is_active
            || $seller->email_verified_at === null
            || ! $seller->businesses()
                ->where('status', 'approved')
                ->exists()
        ) {
            $this->invalid('The seller is not currently eligible.');
        }
    }

    private function ensureAvailable(
        Product|PetSaleListing $item,
        int $quantity,
    ): void {
        if (! $item->is_approved || ! $item->is_active) {
            $this->invalid('This listing is not available for orders.');
        }

        if ($item instanceof Product) {
            if ($item->quantity < $quantity) {
                $this->invalid('Insufficient product stock.');
            }
        } elseif ($item->status !== 'available' || $quantity !== 1) {
            $this->invalid('This pet listing is not available.');
        }
    }

    private function priceInCents(string $price): int
    {
        if (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price)) {
            $this->invalid('The listing has an invalid price.');
        }

        $parts = explode('.', $price, 2);

        return ((int) $parts[0] * 100)
            + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function decimalPrice(int $cents): string
    {
        return intdiv($cents, 100).'.'
            .str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $this->verifiedUser($request);

        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        return response()->json(
            MarketplaceOrder::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function incoming(Request $request): JsonResponse
    {
        $user = $this->verifiedUser($request);

        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        return response()->json(
            MarketplaceOrder::query()
                ->where('seller_id', $user->id)
                ->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->verifiedUser($request);

        $data = $request->validate([
            'type' => ['required', Rule::in(['product', 'pet-sale'])],
            'id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'between:1,10000'],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'user_id' => ['prohibited'],
            'seller_id' => ['prohibited'],
            'product_id' => ['prohibited'],
            'pet_sale_listing_id' => ['prohibited'],
            'reference' => ['prohibited'],
            'status' => ['prohibited'],
            'unit_price' => ['prohibited'],
            'total_price' => ['prohibited'],
            'currency' => ['prohibited'],
        ]);

        $order = DB::transaction(function () use ($user, $data) {
            $model = $data['type'] === 'product'
                ? Product::class
                : PetSaleListing::class;

            $item = $model::query()
                ->lockForUpdate()
                ->findOrFail($data['id']);

            if ((int) $item->user_id === (int) $user->id) {
                $this->invalid('You cannot order your own listing.');
            }

            $seller = $item->user;

            if (! $seller) {
                $this->invalid('The seller is unavailable.');
            }

            $this->ensureSeller($seller);

            $quantity = (int) $data['quantity'];
            $this->ensureAvailable($item, $quantity);

            $unitCents = $this->priceInCents((string) $item->price);

            return MarketplaceOrder::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $user->id,
                'seller_id' => $item->user_id,
                'product_id' => $item instanceof Product
                    ? $item->id
                    : null,
                'pet_sale_listing_id' => $item instanceof PetSaleListing
                    ? $item->id
                    : null,
                'quantity' => $quantity,
                'unit_price' => $this->decimalPrice($unitCents),
                'total_price' => $this->decimalPrice(
                    $unitCents * $quantity
                ),
                'currency' => 'LKR',
                'message' => $data['message'] ?? null,
                'status' => 'pending',
            ]);
        });

        return response()->json([
            'message' => 'Order request submitted.',
            'data' => $order,
        ], 201);
    }

    public function respond(
        Request $request,
        MarketplaceOrder $marketplaceOrder,
    ): JsonResponse {
        $user = $this->verifiedUser($request);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'accepted',
                    'rejected',
                    'completed',
                    'cancelled',
                ]),
            ],
        ]);

        $updated = DB::transaction(function () use (
            $marketplaceOrder,
            $user,
            $data,
        ) {
            $order = MarketplaceOrder::query()
                ->lockForUpdate()
                ->findOrFail($marketplaceOrder->id);

            $target = $data['status'];

            $authorizedId = $target === 'cancelled'
                ? $order->user_id
                : $order->seller_id;

            abort_unless(
                (int) $authorizedId === (int) $user->id,
                403,
                'You cannot perform this action on this order.',
            );

            if ($order->status === $target) {
                return $order;
            }

            $allowed = match ($order->status) {
                'pending' => ['accepted', 'rejected', 'cancelled'],
                'accepted' => ['completed', 'cancelled'],
                default => [],
            };

            if (! in_array($target, $allowed, true)) {
                $this->invalid(
                    'This order cannot move to the requested status.'
                );
            }

            if (
                ($order->product_id === null)
                === ($order->pet_sale_listing_id === null)
            ) {
                $this->invalid('This order has an invalid item reference.');
            }

            $item = $order->product_id !== null
                ? Product::query()->lockForUpdate()
                    ->findOrFail($order->product_id)
                : PetSaleListing::query()->lockForUpdate()
                    ->findOrFail($order->pet_sale_listing_id);

            if ((int) $item->user_id !== (int) $order->seller_id) {
                $this->invalid('The listing owner has changed.');
            }

            if ($target === 'accepted') {
                $this->ensureSeller($user);
                $this->ensureAvailable($item, $order->quantity);

                $customer = $order->user;

                if (
                    ! $customer
                    || ! $customer->is_active
                    || $customer->email_verified_at === null
                ) {
                    $this->invalid('The customer is not eligible.');
                }

                if ($item instanceof Product) {
                    $item->quantity -= $order->quantity;
                } else {
                    $item->status = 'reserved';
                }

                $item->save();
            }

            if (
                $target === 'cancelled'
                && $order->status === 'accepted'
            ) {
                if ($item instanceof Product) {
                    $item->quantity += $order->quantity;
                } else {
                    if ($item->status !== 'reserved') {
                        $this->invalid(
                            'The pet listing is no longer reserved. Contact the seller.'
                        );
                    }

                    $item->status = 'available';
                }

                $item->save();
            }

            if ($target === 'completed') {
                if ($item instanceof PetSaleListing) {
                    if ($item->status !== 'reserved') {
                        $this->invalid(
                            'The pet listing must be reserved before completion.'
                        );
                    }

                    $item->status = 'sold';
                    $item->save();
                }

                $order->completed_at = now();
            }

            if ($target === 'cancelled') {
                $order->cancelled_at = now();
            }

            $order->status = $target;
            $order->save();

            return $order;
        });

        return response()->json([
            'message' => 'Order updated.',
            'data' => $updated,
        ]);
    }
}