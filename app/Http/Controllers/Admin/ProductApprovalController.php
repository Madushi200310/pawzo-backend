<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductApprovalController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');

        $query = Product::with(['user:id,name,email', 'category']);

        if ($status === 'pending') {
            $query->where('is_approved', false);
        } elseif ($status === 'approved') {
            $query->where('is_approved', true);
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function show(Product $product)
    {
        return response()->json(['data' => $product->load(['user', 'category'])]);
    }

    public function approve(Product $product)
    {
        $product->update([
            'is_approved'      => true,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Product approved.',
            'data'    => $product->fresh('category'),
        ]);
    }

    public function reject(Request $request, Product $product)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $product->update([
            'is_approved'      => false,
            'rejection_reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Product rejected.',
            'data'    => $product->fresh('category'),
        ]);
    }

    public function stats()
    {
        return response()->json([
            'pending'  => Product::where('is_approved', false)->count(),
            'approved' => Product::where('is_approved', true)->count(),
            'total'    => Product::count(),
        ]);
    }
}