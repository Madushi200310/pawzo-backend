<?php

namespace App\Http\Controllers;

use App\Models\ListingCategory;
use App\Models\Pet;
use App\Models\PetRental;
use App\Models\PetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ListingCategoryController extends Controller
{
    private function admin(Request $request): void
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
        $isAdminRoute = $request->is('api/admin/*');

        if ($isAdminRoute) {
            $this->admin($request);
        }

        $data = $request->validate([
            'group' => ['required', Rule::in(ListingCategory::GROUPS)],
        ]);

        $query = ListingCategory::where('group', $data['group']);

        if (! $isAdminRoute) {
            $query->where('is_active', true);
        }

        $categories = $query->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'count' => $categories->count(),
            'data' => $categories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->admin($request);

        $data = $request->validate([
            'group' => ['required', Rule::in(ListingCategory::GROUPS)],
            'value' => [
                'required',
                'string',
                'max:30',
                'regex:/^[a-z][a-z0-9_-]*$/',
            ],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => [
                'sometimes', 'required', 'integer', 'between:0,1000000',
            ],
        ]);

        $name = trim($data['name']);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['A display name is required.'],
            ]);
        }

        validator($data, [
            'value' => [
                Rule::unique('listing_categories', 'value')
                    ->where('group', $data['group']),
            ],
        ])->validate();

        $category = ListingCategory::create(array_merge($data, [
            'name' => $name,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]));

        return response()->json([
            'message' => 'Category created.',
            'data' => $category->fresh(),
        ], 201);
    }

    public function update(
        Request $request,
        ListingCategory $listingCategory,
    ): JsonResponse {
        $this->admin($request);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => [
                'sometimes', 'required', 'integer', 'between:0,1000000',
            ],
            'group' => ['prohibited'],
            'value' => ['prohibited'],
        ]);

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);

            if ($data['name'] === '') {
                throw ValidationException::withMessages([
                    'name' => ['A display name is required.'],
                ]);
            }
        }

        $listingCategory->update($data);

        return response()->json([
            'message' => 'Category updated.',
            'data' => $listingCategory->fresh(),
        ]);
    }

    public function destroy(
        Request $request,
        ListingCategory $listingCategory,
    ): JsonResponse {
        $this->admin($request);

        DB::transaction(function () use ($listingCategory) {
            $locked = ListingCategory::query()
                ->lockForUpdate()
                ->findOrFail($listingCategory->id);

            [$model, $column] = match ($locked->group) {
                'pet' => [Pet::class, 'type'],
                'service' => [PetService::class, 'service_type'],
                'rental' => [PetRental::class, 'pet_type'],
            };

            // Include soft-deleted pets so they can still be restored.
            $query = $locked->group === 'pet'
                ? $model::withTrashed()
                : $model::query();

            if ($query->whereRaw(
                "LOWER({$column}) = ?",
                [mb_strtolower($locked->value)],
            )->exists()) {
                throw ValidationException::withMessages([
                    'category' => [
                        'This category is in use. Deactivate it instead.',
                    ],
                ]);
            }

            $locked->delete();
        });

        return response()->json(['message' => 'Category deleted.']);
    }
}