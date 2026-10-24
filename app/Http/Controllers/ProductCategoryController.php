<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductCategoryController extends Controller
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

    public function index(Request $request)
    {
        $query = ProductCategory::query()->ordered();

        if ($request->is('api/admin/*')) {
            $this->authorizeAdmin($request);
        } else {
            $query->active();
        }

        $categories = $query->get();

        return response()->json([
            'count' => $categories->count(),
            'data' => $categories,
        ]);
    }

    public function show(
        Request $request,
        ProductCategory $productCategory,
    ) {
        if ($request->is('api/admin/*')) {
            $this->authorizeAdmin($request);
        } else {
            abort_unless($productCategory->is_active, 404);
        }

        return response()->json(['data' => $productCategory]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('product_categories', 'name'),
            ],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => [
                'sometimes', 'nullable', 'string', 'max:2000',
            ],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        $data['slug'] = $this->validatedSlug($data['name']);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return response()->json([
            'message' => 'Category created.',
            'data' => ProductCategory::create($data),
        ], 201);
    }

    public function update(
        Request $request,
        ProductCategory $productCategory,
    ) {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('product_categories', 'name')
                    ->ignore($productCategory->id),
            ],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => [
                'sometimes', 'nullable', 'string', 'max:2000',
            ],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        if (isset($data['name'])) {
            $data['slug'] = $this->validatedSlug(
                $data['name'],
                $productCategory->id,
            );
        }

        $productCategory->update($data);

        return response()->json([
            'message' => 'Category updated.',
            'data' => $productCategory->fresh(),
        ]);
    }

    public function destroy(
        Request $request,
        ProductCategory $productCategory,
    ) {
        $this->authorizeAdmin($request);

        DB::transaction(function () use ($productCategory) {
            $locked = ProductCategory::query()
                ->lockForUpdate()
                ->findOrFail($productCategory->id);

            if ($locked->products()->exists()) {
                throw ValidationException::withMessages([
                    'product_category' => [
                        'This category is used by products. Deactivate it instead.',
                    ],
                ]);
            }

            $locked->delete();
        });

        return response()->json(['message' => 'Category deleted.']);
    }

    private function validatedSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);

        $query = ProductCategory::where('slug', $slug);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($slug === '' || $query->exists()) {
            throw ValidationException::withMessages([
                'name' => [
                    'Choose a name that produces a non-empty, unique URL slug.',
                ],
            ]);
        }

        return $slug;
    }
}