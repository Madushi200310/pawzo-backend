<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Public browsing returns approved, active products.
     * Owners can request their own products with ?mine=1.
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'user:id,name']);

        if ($request->filled('category_id')) {
            $query->where('product_category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($query) use ($search) {
                $query->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        if ($request->user() && $request->boolean('mine')) {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->approved();
        }

        return response()->json($query->latest()->paginate(20));
    }

    /**
     * Submit a product for admin approval.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_category_id' => 'required|exists:product_categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'brand' => 'nullable|string|max:100',
            'sku' => 'nullable|string|max:100',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $photoPaths = [];

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $photoPaths[] = $photo->store('products', 'public');
            }
        }

        $product = Product::create([
            'user_id' => $request->user()->id,
            'product_category_id' => $validated['product_category_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'quantity' => $validated['quantity'],
            'brand' => $validated['brand'] ?? null,
            'sku' => $validated['sku'] ?? null,
            'photos' => $photoPaths,
            'is_approved' => false,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Product submitted. Awaiting admin approval.',
            'data' => $product->load('category'),
        ], 201);
    }

    /**
     * Owners and admins can view unpublished products.
     * Everyone else can only view approved, active products.
     */
    public function show(Request $request, Product $product)
    {
        $user = $request->user();

        $isOwner = $user
            && (int) $product->user_id === (int) $user->id;

        $isAdmin = $user && $user->isAdmin();

        if (
            (! $product->is_approved || ! $product->is_active)
            && ! $isOwner
            && ! $isAdmin
        ) {
            return response()->json([
                'message' => 'Not found.',
            ], 404);
        }

        return response()->json([
            'data' => $product->load(['category', 'user:id,name']),
        ]);
    }

    /**
     * Update an owned product.
     */
    public function update(Request $request, Product $product)
    {
        if ((int) $product->user_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'price' => 'sometimes|required|numeric|min:0',
            'quantity' => 'sometimes|required|integer|min:0',
            'brand' => 'nullable|string|max:100',
            'sku' => 'nullable|string|max:100',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        if ($request->hasFile('photos')) {
            foreach ($product->photos ?? [] as $old) {
                Storage::disk('public')->delete($old);
            }

            $newPaths = [];

            foreach ($request->file('photos') as $photo) {
                $newPaths[] = $photo->store('products', 'public');
            }

            $validated['photos'] = $newPaths;
            $validated['is_approved'] = false;
        }

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated.',
            'data' => $product->fresh('category'),
        ]);
    }

    /**
     * Delete an owned product.
     */
    public function destroy(Request $request, Product $product)
    {
        if ((int) $product->user_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        foreach ($product->photos ?? [] as $path) {
            Storage::disk('public')->delete($path);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted.',
        ]);
    }
}