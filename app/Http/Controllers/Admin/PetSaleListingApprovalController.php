<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PetSaleListing;
use Illuminate\Http\Request;

class PetSaleListingApprovalController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');

        $query = PetSaleListing::with(['user:id,name,email']);

        if ($status === 'pending') {
            $query->where('is_approved', false);
        } elseif ($status === 'approved') {
            $query->where('is_approved', true);
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function show(PetSaleListing $petSaleListing)
    {
        return response()->json(['data' => $petSaleListing->load('user')]);
    }

    public function approve(PetSaleListing $petSaleListing)
    {
        $petSaleListing->update([
            'is_approved'      => true,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Pet listing approved.',
            'data'    => $petSaleListing->fresh('user:id,name'),
        ]);
    }

    public function reject(Request $request, PetSaleListing $petSaleListing)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $petSaleListing->update([
            'is_approved'      => false,
            'rejection_reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Pet listing rejected.',
            'data'    => $petSaleListing->fresh('user:id,name'),
        ]);
    }

    public function stats()
    {
        return response()->json([
            'pending'  => PetSaleListing::where('is_approved', false)->count(),
            'approved' => PetSaleListing::where('is_approved', true)->count(),
            'sold'     => PetSaleListing::where('status', 'sold')->count(),
            'total'    => PetSaleListing::count(),
        ]);
    }
}