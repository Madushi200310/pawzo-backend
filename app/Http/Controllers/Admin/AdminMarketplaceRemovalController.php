<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PetSaleListing;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminMarketplaceRemovalController extends Controller
{
    public function product(
        Request $request,
        Product $product,
    ): JsonResponse {
        return $this->remove($request, $product);
    }

    public function petSale(
        Request $request,
        PetSaleListing $petSaleListing,
    ): JsonResponse {
        return $this->remove($request, $petSaleListing);
    }

    private function remove(
        Request $request,
        Product|PetSaleListing $listing,
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user
                && $user->isAdmin()
                && $user->is_active
                && $user->email_verified_at !== null,
            403,
        );

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $updated = DB::transaction(function () use ($listing, $data) {
            $record = $listing->newQuery()
                ->lockForUpdate()
                ->findOrFail($listing->getKey());

            $record->update([
                'is_active' => false,
                'is_approved' => false,
                'rejection_reason' => $data['reason'],
            ]);

            return $record->fresh();
        });

        return response()->json([
            'message' => 'Listing removed from public access.',
            'data' => $updated,
        ]);
    }
}