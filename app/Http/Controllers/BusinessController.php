<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    /**
     * List user's businesses (own) + approved public ones.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Own businesses
        $myBusinesses = Business::with(['businessType', 'documents'])
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        // Approved public businesses (for browsing)
        $publicBusinesses = Business::with('businessType')
            ->approved()
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'my_businesses'     => $myBusinesses,
            'public_businesses' => $publicBusinesses,
        ]);
    }

    /**
     * Submit a new business verification application.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'business_type_id' => 'required|exists:business_types,id',
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string|max:5000',
            'address'          => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:100',
            'district'         => 'nullable|string|max:100',
            'phone'            => 'required|string|max:20',
            'email'            => 'required|email|max:255',
            'website'          => 'nullable|url|max:255',

            // Documents
            'documents'                 => 'required|array|min:1|max:5',
            'documents.*.type'          => 'required|string|max:100',
            'documents.*.file'          => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240', // 10MB
        ]);

        // Ensure the business type is active
        $type = BusinessType::where('id', $validated['business_type_id'])
            ->where('is_active', true)
            ->firstOrFail();

        // Prevent duplicate pending applications for the same type
        $existing = Business::where('user_id', $request->user()->id)
            ->where('business_type_id', $type->id)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'You already have a pending or approved application for this business type.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Create business
            $business = Business::create([
                'user_id'          => $request->user()->id,
                'business_type_id' => $type->id,
                'name'             => $validated['name'],
                'description'      => $validated['description'] ?? null,
                'address'          => $validated['address'] ?? null,
                'city'             => $validated['city'] ?? null,
                'district'         => $validated['district'] ?? null,
                'phone'            => $validated['phone'],
                'email'            => $validated['email'],
                'website'          => $validated['website'] ?? null,
                'status'           => 'pending',
                'submitted_at'     => now(),
            ]);

            // Store documents
            foreach ($validated['documents'] as $doc) {
                $file = $doc['file'];
                $path = $file->store("business_documents/{$business->id}", 'public');

                BusinessDocument::create([
                    'business_id'   => $business->id,
                    'document_type' => $doc['type'],
                    'file_path'     => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType(),
                    'file_size'     => $file->getSize(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message'  => 'Business application submitted successfully. Awaiting admin approval.',
                'business' => $business->load('businessType', 'documents'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to submit application.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show a business (owner or admin sees everything; public sees approved only).
     */
    public function show(Request $request, Business $business)
    {
        $user = $request->user();

        $isOwner = $user && $business->user_id === $user->id;
        $isAdmin = $user && $user->isAdmin();
        $isApproved = $business->isApproved();

        if (!$isOwner && !$isAdmin && !$isApproved) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $business->load('businessType', 'documents', 'user:id,name,email');

        return response()->json(['data' => $business]);
    }

    /**
     * Update own business (only while pending, or if rejected → resubmit).
     */
    public function update(Request $request, Business $business)
    {
        // Ensure owner
        if ($business->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($business->isApproved()) {
            return response()->json([
                'message' => 'Approved businesses cannot be edited here. Contact admin.',
            ], 403);
        }

        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'address'     => 'nullable|string|max:255',
            'city'        => 'nullable|string|max:100',
            'district'    => 'nullable|string|max:100',
            'phone'       => 'sometimes|required|string|max:20',
            'email'       => 'sometimes|required|email|max:255',
            'website'     => 'nullable|url|max:255',
        ]);

        $business->update($validated);

        return response()->json([
            'message'  => 'Business updated.',
            'business' => $business->fresh('businessType', 'documents'),
        ]);
    }

    /**
     * Delete own business.
     */
    public function destroy(Request $request, Business $business)
    {
        if ($business->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Delete documents from storage
        foreach ($business->documents as $doc) {
            Storage::disk('public')->delete($doc->file_path);
        }

        $business->delete();

        return response()->json(['message' => 'Business deleted.']);
    }
}