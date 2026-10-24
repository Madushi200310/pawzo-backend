<?php

namespace App\Http\Controllers;

use App\Models\PetSaleListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PetSaleListingController extends Controller
{
    /**
     * Public browsing returns approved, active listings.
     * Owners can request their own listings with ?mine=1.
     */
    public function index(Request $request)
    {
        $query = PetSaleListing::with('user:id,name');

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
            $search = $request->search;

            $query->where(function ($query) use ($search) {
                $query->where('breed', 'ILIKE', "%{$search}%")
                    ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->user() && $request->boolean('mine')) {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->approved();
        }

        return response()->json($query->latest()->paginate(20));
    }

    /**
     * Submit a pet sale listing for admin approval.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'pet_type' => 'required|string|max:50',
            'breed' => 'nullable|string|max:100',
            'age' => 'nullable|string|max:50',
            'gender' => 'required|in:male,female,unknown',
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'price' => 'required|numeric|min:0',
            'is_negotiable' => 'boolean',
            'contact_phone' => 'required|string|max:20',
            'contact_whatsapp' => 'nullable|string|max:20',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
            'certificates' => 'nullable|array|max:3',
            'certificates.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);

        $photoPaths = [];

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $photoPaths[] = $photo->store('pet_sale_photos', 'public');
            }
        }

        $certificatePaths = [];

        if ($request->hasFile('certificates')) {
            foreach ($request->file('certificates') as $certificate) {
                $certificatePaths[] = $certificate->store(
                    'pet_certificates',
                    'public',
                );
            }
        }

        $listing = PetSaleListing::create([
            'user_id' => $request->user()->id,
            'pet_type' => $validated['pet_type'],
            'breed' => $validated['breed'] ?? null,
            'age' => $validated['age'] ?? null,
            'gender' => $validated['gender'],
            'color' => $validated['color'] ?? null,
            'description' => $validated['description'] ?? null,
            'location' => $validated['location'] ?? null,
            'city' => $validated['city'] ?? null,
            'district' => $validated['district'] ?? null,
            'price' => $validated['price'],
            'is_negotiable' => $request->boolean('is_negotiable'),
            'photos' => $photoPaths,
            'certificates' => $certificatePaths,
            'contact_phone' => $validated['contact_phone'],
            'contact_whatsapp' => $validated['contact_whatsapp'] ?? null,
            'status' => 'available',
            'is_approved' => false,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Pet listing submitted. Awaiting admin approval.',
            'data' => $listing,
        ], 201);
    }

    /**
     * Owners and admins can view unpublished listings.
     * Everyone else can only view approved, active listings.
     */
    public function show(Request $request, PetSaleListing $petSaleListing)
    {
        $user = $request->user();

        $isOwner = $user
            && (int) $petSaleListing->user_id === (int) $user->id;

        $isAdmin = $user && $user->isAdmin();

        if (
            (! $petSaleListing->is_approved || ! $petSaleListing->is_active)
            && ! $isOwner
            && ! $isAdmin
        ) {
            return response()->json([
                'message' => 'Not found.',
            ], 404);
        }

        return response()->json([
            'data' => $petSaleListing->load('user:id,name,phone,email'),
        ]);
    }

    /**
     * Update an owned pet sale listing.
     */
    public function update(Request $request, PetSaleListing $petSaleListing)
    {
        if ((int) $petSaleListing->user_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $validated = $request->validate([
            'pet_type' => 'sometimes|required|string|max:50',
            'breed' => 'nullable|string|max:100',
            'age' => 'nullable|string|max:50',
            'gender' => 'sometimes|required|in:male,female,unknown',
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'price' => 'sometimes|required|numeric|min:0',
            'is_negotiable' => 'boolean',
            'contact_phone' => 'sometimes|required|string|max:20',
            'contact_whatsapp' => 'nullable|string|max:20',
            'status' => 'sometimes|in:available,reserved,sold',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
            'certificates' => 'nullable|array|max:3',
            'certificates.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);

        if ($request->hasFile('photos')) {
            foreach ($petSaleListing->photos ?? [] as $old) {
                Storage::disk('public')->delete($old);
            }

            $newPhotos = [];

            foreach ($request->file('photos') as $photo) {
                $newPhotos[] = $photo->store('pet_sale_photos', 'public');
            }

            $validated['photos'] = $newPhotos;
            $validated['is_approved'] = false;
        }

        if ($request->hasFile('certificates')) {
            foreach ($petSaleListing->certificates ?? [] as $old) {
                Storage::disk('public')->delete($old);
            }

            $newCertificates = [];

            foreach ($request->file('certificates') as $certificate) {
                $newCertificates[] = $certificate->store(
                    'pet_certificates',
                    'public',
                );
            }

            $validated['certificates'] = $newCertificates;
        }

        $petSaleListing->update($validated);

        return response()->json([
            'message' => 'Listing updated.',
            'data' => $petSaleListing->fresh('user:id,name'),
        ]);
    }

    /**
     * Delete an owned pet sale listing.
     */
    public function destroy(Request $request, PetSaleListing $petSaleListing)
    {
        if ((int) $petSaleListing->user_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        foreach ($petSaleListing->photos ?? [] as $path) {
            Storage::disk('public')->delete($path);
        }

        foreach ($petSaleListing->certificates ?? [] as $path) {
            Storage::disk('public')->delete($path);
        }

        $petSaleListing->delete();

        return response()->json([
            'message' => 'Listing deleted.',
        ]);
    }
}