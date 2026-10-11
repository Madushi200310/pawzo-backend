<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductCategoryController extends Controller
{
    public function index()
    {
        return response()->json([
            'count' => ProductCategory::count(),
            'data'  => ProductCategory::ordered()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:product_categories,name',
            'icon'        => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'is_active'   => 'boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $cat = ProductCategory::create($validated);

        return response()->json([
            'message' => 'Category created.',
            'data'    => $cat,
        ], 201);
    }

    public function show(ProductCategory $productCategory)
    {
        return response()->json(['data' => $productCategory]);
    }

    public function update(Request $request, ProductCategory $productCategory)
    {
        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:255',
                              Rule::unique('product_categories', 'name')->ignore($productCategory->id)],
            'icon'        => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'is_active'   => 'boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $productCategory->update($validated);

        return response()->json([
            'message' => 'Category updated.',
            'data'    => $productCategory,
        ]);
    }

    public function destroy(ProductCategory $productCategory)
    {
        $productCategory->delete();

        return response()->json(['message' => 'Category deleted.']);
    }
}