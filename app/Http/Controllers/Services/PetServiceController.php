<?php

namespace App\Http\Controllers\Services;

use App\Http\Controllers\Controller;
use App\Http\Requests\Services\ListPetServicesRequest;
use App\Http\Requests\Services\SavePetServiceRequest;
use App\Http\Resources\PetServicePublicResource;
use App\Http\Resources\PetServiceResource;
use App\Models\PetService;
use App\Services\Services\PetServiceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PetServiceController extends Controller
{
    /**
     * Public browse — only approved services, published-owner accounts only.
     */
    public function index(ListPetServicesRequest $request): AnonymousResourceCollection
    {
        $query = PetService::query()
            ->where('status', 'approved')
            ->whereHas('user', fn (Builder $u) =>
                $u->where('is_active', true)->whereNotNull('email_verified_at'));

        return $this->listing($request, $query, public: true);
    }

    /**
     * Provider's own listings (any status).
     */
    public function mine(ListPetServicesRequest $request): AnonymousResourceCollection
    {
        return $this->listing(
            $request,
            PetService::query()->where('user_id', $request->user()->id),
            public: false,
        );
    }

    public function store(SavePetServiceRequest $request, PetServiceService $service): JsonResponse
    {
        $created = $service->create(
            $request->user(),
            $request->validated(),
            $request->file('photos'),
        );

        return (new PetServiceResource($created))
            ->additional(['message' => 'Service listing created. Awaiting admin approval.'])
            ->response()->setStatusCode(201);
    }

    public function show(PetService $petService): PetServicePublicResource
    {
        // Public can only see approved services. Owner can see their own.
        $viewer = request()->user();
        $isOwner = $viewer && (int) $petService->user_id === (int) $viewer->id;

        if (! $isOwner && $petService->status !== 'approved') {
            abort(404);
        }

        abort_unless(
            $petService->user()->where('is_active', true)->whereNotNull('email_verified_at')->exists(),
            404,
        );

        return new PetServicePublicResource($petService->load('photos'));
    }

    public function update(
        SavePetServiceRequest $request,
        PetService $petService,
        PetServiceService $service
    ): PetServiceResource {
        return (new PetServiceResource($service->update($petService, $request->validated())))
            ->additional(['message' => 'Service listing updated successfully.']);
    }

    public function destroy(PetService $petService, PetServiceService $service): JsonResponse
    {
        Gate::authorize('delete', $petService);
        $service->delete($petService);

        return response()->json(['message' => 'Service listing deleted successfully.']);
    }

    public function addPhotos(
        Request $request,
        PetService $petService,
        PetServiceService $service
    ): PetServiceResource {
        Gate::authorize('update', $petService);
        $request->validate(SavePetServiceRequest::photoRules());

        return (new PetServiceResource($service->addPhotos($petService, $request->file('photos'))))
            ->additional(['message' => 'Photos added successfully.']);
    }

    public function deletePhoto(
        PetService $petService,
        int $photo,
        PetServiceService $service
    ): JsonResponse {
        Gate::authorize('update', $petService);
        $service->deletePhoto($petService, $photo);

        return response()->json(['message' => 'Photo deleted successfully.']);
    }

    private function listing(
        ListPetServicesRequest $request,
        Builder $query,
        bool $public
    ): AnonymousResourceCollection {
        $data = $request->validated();

        if (isset($data['service_type'])) {
            $query->where('service_type', $data['service_type']);
        }

        if (! $public && isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (isset($data['location'])) {
            $query->whereRaw('LOWER(location_name) LIKE ?', ['%'.mb_strtolower($data['location']).'%']);
        }

        if (isset($data['min_price'])) {
            $query->where('price_from', '>=', $data['min_price']);
        }

        if (isset($data['max_price'])) {
            $query->where('price_from', '<=', $data['max_price']);
        }

        if (isset($data['currency'])) {
            $query->where('currency', strtoupper($data['currency']));
        }

        if (isset($data['q'])) {
            $term = '%'.mb_strtolower($data['q']).'%';
            $query->where(function (Builder $search) use ($term) {
                foreach (['name', 'short_description', 'description', 'location_name'] as $column) {
                    $search->orWhereRaw('LOWER('.$column.') LIKE ?', [$term]);
                }
            });
        }

        $query->with('photos')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $paginated = $query->paginate((int) ($data['per_page'] ?? 15))->withQueryString();

        return $public
            ? PetServicePublicResource::collection($paginated)
            : PetServiceResource::collection($paginated);
    }
}