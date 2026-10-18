<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessVerificationController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
    ) {}

    /**
     * List verification requests, optionally filtered by status.
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');

        $query = Business::with([
            'user:id,name,email',
            'businessType',
            'documents',
        ]);

        if (in_array(
            $status,
            ['pending', 'approved', 'rejected'],
            true
        )) {
            $query->where('status', $status);
        }

        return response()->json(
            $query->latest('submitted_at')->paginate(20)
        );
    }

    /**
     * Show a verification request and its documents.
     */
    public function show(Business $business)
    {
        $business->load([
            'user',
            'businessType',
            'documents',
            'approvedBy:id,name',
        ]);

        return response()->json([
            'data' => $business,
        ]);
    }

    /**
     * Approve a business and notify its owner atomically.
     */
    public function approve(Request $request, Business $business)
    {
        return DB::transaction(function () use ($request, $business) {
            $locked = Business::query()
                ->lockForUpdate()
                ->findOrFail($business->id);

            // Preserve the existing response for repeated approval.
            if ($locked->isApproved()) {
                return response()->json([
                    'message' => 'Already approved.',
                ], 422);
            }

            $locked->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'rejection_reason' => null,
            ]);

            $this->notifications->businessApproved(
                owner: $locked->user()->firstOrFail(),
                businessId: (int) $locked->id,
                businessName: $locked->name,
            );

            return response()->json([
                'message' => 'Business approved successfully.',
                'business' => $locked->fresh('businessType'),
            ]);
        });
    }

    /**
     * Reject a business and notify its owner atomically.
     */
    public function reject(Request $request, Business $business)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use (
            $request,
            $business,
            $validated,
        ) {
            $locked = Business::query()
                ->lockForUpdate()
                ->findOrFail($business->id);

            $reason = $validated['reason'];

            // Identical retries do not create duplicate notifications.
            if (
                $locked->isRejected()
                && $locked->rejection_reason === $reason
            ) {
                return response()->json([
                    'message' => 'Business rejected.',
                    'business' => $locked->fresh('businessType'),
                ]);
            }

            $locked->update([
                'status' => 'rejected',
                'approved_at' => null,
                'approved_by' => $request->user()->id,
                'rejection_reason' => $reason,
            ]);

            $this->notifications->businessRejected(
                owner: $locked->user()->firstOrFail(),
                businessId: (int) $locked->id,
                businessName: $locked->name,
                reason: $reason,
            );

            return response()->json([
                'message' => 'Business rejected.',
                'business' => $locked->fresh('businessType'),
            ]);
        });
    }

    /**
     * Business verification statistics.
     */
    public function stats()
    {
        return response()->json([
            'pending' => Business::where('status', 'pending')->count(),
            'approved' => Business::where('status', 'approved')->count(),
            'rejected' => Business::where('status', 'rejected')->count(),
            'total' => Business::count(),
        ]);
    }
}