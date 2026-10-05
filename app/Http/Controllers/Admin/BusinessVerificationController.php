<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;

class BusinessVerificationController extends Controller
{
    /**
     * List verification requests (filter by status).
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');

        $query = Business::with(['user:id,name,email', 'businessType', 'documents']);

        if (in_array($status, ['pending', 'approved', 'rejected'])) {
            $query->where('status', $status);
        }

        $businesses = $query->latest('submitted_at')->paginate(20);

        return response()->json($businesses);
    }

    /**
     * Show a single verification request with all documents.
     */
    public function show(Business $business)
    {
        $business->load(['user', 'businessType', 'documents', 'approvedBy:id,name']);

        return response()->json(['data' => $business]);
    }

    /**
     * Approve a business.
     */
    public function approve(Request $request, Business $business)
    {
        if ($business->isApproved()) {
            return response()->json(['message' => 'Already approved.'], 422);
        }

        $business->update([
            'status'           => 'approved',
            'approved_at'      => now(),
            'approved_by'      => $request->user()->id,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message'  => 'Business approved successfully.',
            'business' => $business->fresh('businessType'),
        ]);
    }

    /**
     * Reject a business with a reason.
     */
    public function reject(Request $request, Business $business)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $business->update([
            'status'           => 'rejected',
            'approved_at'      => null,
            'approved_by'      => $request->user()->id,
            'rejection_reason' => $validated['reason'],
        ]);

        return response()->json([
            'message'  => 'Business rejected.',
            'business' => $business->fresh('businessType'),
        ]);
    }

    /**
     * Stats for admin dashboard.
     */
    public function stats()
    {
        return response()->json([
            'pending'  => Business::where('status', 'pending')->count(),
            'approved' => Business::where('status', 'approved')->count(),
            'rejected' => Business::where('status', 'rejected')->count(),
            'total'    => Business::count(),
        ]);
    }
}