<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Services\ListPetServicesRequest;
use App\Http\Requests\Services\RespondPetServiceRequest;
use App\Http\Resources\PetServiceResource;
use App\Models\PetService;
use App\Services\Services\PetServiceModerationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminPetServiceController extends Controller
{
    public function index(ListPetServicesRequest $request): AnonymousResourceCollection
    {
        $query = PetService::query()->with('photos');
        $data = $request->validated();

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['service_type'])) {
            $query->where('service_type', $data['service_type']);
        }
        if (isset($data['location'])) {
            $query->whereRaw('LOWER(location_name) LIKE ?', ['%'.mb_strtolower($data['location']).'%']);
        }
        if (isset($data['q'])) {
            $term = '%'.mb_strtolower($data['q']).'%';
            $query->where(function (Builder $search) use ($term) {
                foreach (['name', 'description', 'location_name'] as $column) {
                    $search->orWhereRaw('LOWER('.$column.') LIKE ?', [$term]);
                }
            });
        }

        return PetServiceResource::collection(
            $query->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function show(PetService $petService): PetServiceResource
    {
        return new PetServiceResource($petService->load('photos'));
    }

    public function respond(
        RespondPetServiceRequest $request,
        PetService $petService,
        PetServiceModerationService $service
    ): PetServiceResource {
        $data = $request->validated();

        $updated = match ($data['status']) {
            'approved' => $service->approve($petService),
            'rejected' => $service->reject($petService, $data['rejection_reason'] ?? ''),
            'removed' => $service->remove($petService),
        };

        return (new PetServiceResource($updated))
            ->additional(['message' => "Service listing {$data['status']}."]);
    }

    public function destroy(PetService $petService): JsonResponse
    {
        $petService->delete();

        return response()->json(['message' => 'Service listing removed.']);
    }
}