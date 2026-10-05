<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * List products.
     * Public: only approved + active.
     * Authenticated + owner: also returns their own (approved or not).
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'user:id,name']);

        // Filters
        if ($request->filled('category_id')) {
            $query->where('product_category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'ILIKE', "%{$s}%")
                  ->orWhere('description', 'ILIKE', "%{$s}%");
            });
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Auth: if the user is a seller, show them all their own too
        if ($request->user() && $request->boolean('mine')) {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->approved();
        }

        $products = $query->latest()->paginate(20);

        return response()->json($products);
    }

    /**
     * Store a new product (seller).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_category_id' => 'required|exists:product_categories,id',
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string|max:5000',
            'price'               => 'required|numeric|min:0',
            'quantity'            => 'required|integer|min:0',
            'brand'               => 'nullable|string|max:100',
            'sku'                 => 'nullable|string|max:100',
            'photos'              => 'nullable|array|max:5',
            'photos.*'            => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        // Store photos
        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $photoPaths[] = $photo->store('products', 'public');
            }
        }

        $product = Product::create([
            'user_id'             => $request->user()->id,
            'product_category_id' => $validated['product_category_id'],
            'name'                => $validated['name'],
            'description'         => $validated['description'] ?? null,
            'price'               => $validated['price'],
            'quantity'            => $validated['quantity'],
            'brand'               => $validated['brand'] ?? null,
            'sku'                 => $validated['sku'] ?? null,
            'photos'              => $photoPaths,
            'is_approved'         => false,
            'is_active'           => true,
        ]);

        return response()->json([
            'message' => 'Product submitted. Awaiting admin approval.',
            'data'    => $product->load('category'),
        ], 201);
    }

    /**
     * Show a single product.
     */
    public function show(Request $request, Product $product)
    {
        $user = $request->user();
        $isOwner = $user && $product->user_id === $user->id;
        $isAdmin = $user && $user->isAdmin();

        if (!$product->is_approved && !$isOwner && !$isAdmin) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $product->load(['category', 'user:id,name']);

        return response()->json(['data' => $product]);
    }

    /**
     * Update own product (only if not approved OR owner).
     */
    public function update(Request $request, Product $product)
    {
        if ($product->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'price'       => 'sometimes|required|numeric|min:0',
            'quantity'    => 'sometimes|required|integer|min:0',
            'brand'       => 'nullable|string|max:100',
            'sku'         => 'nullable|string|max:100',
            'photos'      => 'nullable|array|max:5',
            'photos.*'    => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        // Handle photos
        if ($request->hasFile('photos')) {
            // Delete old
            foreach ($product->photos ?? [] as $old) {
                Storage::disk('public')->delete($old);
            }
            // Store new
            $newPaths = [];
            foreach ($request->file('photos') as $photo) {
                $newPaths[] = $photo->store('products', 'public');
            }
            $validated['photos'] = $newPaths;

            // Re-approve needed after photo change
            $validated['is_approved'] = false;
        }

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated.',
            'data'    => $product->fresh('category'),
        ]);
    }

    /**
     * Delete own product.
     */
    public function destroy(Request $request, Product $product)
    {
        if ($product->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Delete photos from storage
        foreach ($product->photos ?? [] as $path) {
            Storage::disk('public')->delete($path);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }
}