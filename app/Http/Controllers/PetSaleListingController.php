<?php

namespace App\Http\Controllers;

use App\Models\PetSaleListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PetSaleListingController extends Controller
{
    /**
     * List pet sale listings.
     * Public: only approved + active.
     * Auth: `?mine=1` returns the user's own listings.
     */
    public function index(Request $request)
    {
        $query = PetSaleListing::with('user:id,name');

        // Filters
        if ($request->filled('pet_type')) {
            $query->where('pet_type', $request->pet_type);
        }

        if ($request->filled('breed')) {
            $query->where('breed', 'ILIKE', "%{$request->breed}%");
        }

        if ($request->filled('gender')) {
            $query->where('gender', $request->gender);
        }

        if ($request->filled('city')) {
            $query->where('city', 'ILIKE', "%{$request->city}%");
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('breed', 'ILIKE', "%{$s}%")
                  ->orWhere('description', 'ILIKE', "%{$s}%");
            });
        }

        // Mine vs public
        if ($request->user() && $request->boolean('mine')) {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->approved();
        }

        return response()->json($query->latest()->paginate(20));
    }

    /**
     * Create a new pet sale listing.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'pet_type'         => 'required|string|max:50',
            'breed'            => 'nullable|string|max:100',
            'age'              => 'nullable|string|max:50',
            'gender'           => 'required|in:male,female,unknown',
            'color'            => 'nullable|string|max:50',
            'description'      => 'nullable|string|max:5000',
            'location'         => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:100',
            'district'         => 'nullable|string|max:100',
            'price'            => 'required|numeric|min:0',
            'is_negotiable'    => 'boolean',
            'contact_phone'    => 'required|string|max:20',
            'contact_whatsapp' => 'nullable|string|max:20',

            'photos'           => 'nullable|array|max:5',
            'photos.*'         => 'image|mimes:jpg,jpeg,png,webp|max:5120',

            'certificates'     => 'nullable|array|max:3',
            'certificates.*'   => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);

        // Upload photos
        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $photoPaths[] = $photo->store('pet_sale_photos', 'public');
            }
        }

        // Upload certificates
        $certPaths = [];
        if ($request->hasFile('certificates')) {
            foreach ($request->file('certificates') as $cert) {
                $certPaths[] = $cert->store('pet_certificates', 'public');
            }
        }

        $listing = PetSaleListing::create([
            'user_id'          => $request->user()->id,
            'pet_type'         => $validated['pet_type'],
            'breed'            => $validated['breed'] ?? null,
            'age'              => $validated['age'] ?? null,
            'gender'           => $validated['gender'],
            'color'            => $validated['color'] ?? null,
            'description'      => $validated['description'] ?? null,
            'location'         => $validated['location'] ?? null,
            'city'             => $validated['city'] ?? null,
            'district'         => $validated['district'] ?? null,
            'price'            => $validated['price'],
            'is_negotiable'    => $request->boolean('is_negotiable'),
            'photos'           => $photoPaths,
            'certificates'     => $certPaths,
            'contact_phone'    => $validated['contact_phone'],
            'contact_whatsapp' => $validated['contact_whatsapp'] ?? null,
            'status'           => 'available',
            'is_approved'      => false,
            'is_active'        => true,
        ]);

        return response()->json([
            'message' => 'Pet listing submitted. Awaiting admin approval.',
            'data'    => $listing,
        ], 201);
    }

    /**
     * Show a single listing.
     */
    public function show(Request $request, PetSaleListing $petSaleListing)
    {
        $user = $request->user();
        $isOwner = $user && $petSaleListing->user_id === $user->id;
        $isAdmin = $user && $user->isAdmin();

        if (!$petSaleListing->is_approved && !$isOwner && !$isAdmin) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $petSaleListing->load('user:id,name,phone,email');

        return response()->json(['data' => $petSaleListing]);
    }

    /**
     * Update own listing.
     */
    public function update(Request $request, PetSaleListing $petSaleListing)
    {
        if ($petSaleListing->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'pet_type'         => 'sometimes|required|string|max:50',
            'breed'            => 'nullable|string|max:100',
            'age'              => 'nullable|string|max:50',
            'gender'           => 'sometimes|required|in:male,female,unknown',
            'color'            => 'nullable|string|max:50',
            'description'      => 'nullable|string|max:5000',
            'location'         => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:100',
            'district'         => 'nullable|string|max:100',
            'price'            => 'sometimes|required|numeric|min:0',
            'is_negotiable'    => 'boolean',
            'contact_phone'    => 'sometimes|required|string|max:20',
            'contact_whatsapp' => 'nullable|string|max:20',
            'status'           => 'sometimes|in:available,reserved,sold',

            'photos'           => 'nullable|array|max:5',
            'photos.*'         => 'image|mimes:jpg,jpeg,png,webp|max:5120',

            'certificates'     => 'nullable|array|max:3',
            'certificates.*'   => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);

        // Handle photo replacement
        if ($request->hasFile('photos')) {
            foreach ($petSaleListing->photos ?? [] as $old) {
                Storage::disk('public')->delete($old);
            }
            $newPhotos = [];
            foreach ($request->file('photos') as $p) {
                $newPhotos[] = $p->store('pet_sale_photos', 'public');
            }
            $validated['photos'] = $newPhotos;
            $validated['is_approved'] = false; // re-approval needed
        }

        // Handle certificate replacement
        if ($request->hasFile('certificates')) {
            foreach ($petSaleListing->certificates ?? [] as $old) {
                Storage::disk('public')->delete($old);
            }
            $newCerts = [];
            foreach ($request->file('certificates') as $c) {
                $newCerts[] = $c->store('pet_certificates', 'public');
            }
            $validated['certificates'] = $newCerts;
        }

        $petSaleListing->update($validated);

        return response()->json([
            'message' => 'Listing updated.',
            'data'    => $petSaleListing->fresh('user:id,name'),
        ]);
    }

    /**
     * Delete own listing.
     */
    public function destroy(Request $request, PetSaleListing $petSaleListing)
    {
        if ($petSaleListing->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        foreach ($petSaleListing->photos ?? [] as $p) {
            Storage::disk('public')->delete($p);
        }
        foreach ($petSaleListing->certificates ?? [] as $c) {
            Storage::disk('public')->delete($c);
        }

        $petSaleListing->delete();

        return response()->json(['message' => 'Listing deleted.']);
    }
}