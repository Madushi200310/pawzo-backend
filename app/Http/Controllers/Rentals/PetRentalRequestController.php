<?php

namespace App\Http\Controllers\Rentals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rentals\CreatePetRentalRequestRequest;
use App\Http\Requests\Rentals\RespondPetRentalRequestRequest;
use App\Http\Resources\PetRentalRequestResource;
use App\Models\PetRental;
use App\Models\PetRentalRequest;
use App\Services\Rentals\PetRentalRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PetRentalRequestController extends Controller
{
    public function index(PetRental $petRental): JsonResponse
    {
        Gate::authorize('update', $petRental);

        return response()->json([
            'data' => PetRentalRequestResource::collection(
                $petRental->requests()
                    ->with('requester')
                    ->get()
            ),
        ]);
    }

    public function mine(): JsonResponse
    {
        $requests = PetRentalRequest::query()
            ->with(['rental.photos', 'requester'])
            ->where('requester_id', request()->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => PetRentalRequestResource::collection($requests),
        ]);
    }

    public function store(
        CreatePetRentalRequestRequest $request,
        PetRental $petRental,
        PetRentalRequestService $service,
    ): JsonResponse {
        $created = $service->create(
            $request->user(),
            $petRental,
            $request->validated(),
        );

        return (new PetRentalRequestResource(
            $created->load(['rental.photos', 'requester'])
        ))
            ->additional([
                'message' => 'Rental request created successfully.',
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function respond(
        RespondPetRentalRequestRequest $request,
        PetRentalRequest $petRentalRequest,
        PetRentalRequestService $service,
    ): JsonResponse {
        $data = $request->validated();
        $cancelling = $data['status'] === 'cancelled';

        Gate::authorize(
            $cancelling ? 'cancel' : 'update',
            $petRentalRequest
        );

        $message = $cancelling
            ? ($data['message'] ?? null)
            : ($data['owner_response'] ?? $data['message'] ?? null);

        $updated = $service->respond(
            $petRentalRequest,
            $data['status'],
            $message,
            $request->user(),
        );

        return response()->json([
            'message' => 'Rental request updated successfully.',
            'data' => new PetRentalRequestResource(
                $updated->load(['rental.photos', 'requester'])
            ),
        ]);
    }
}