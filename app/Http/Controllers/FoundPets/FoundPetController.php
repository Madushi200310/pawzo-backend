<?php

namespace App\Http\Controllers\FoundPets;

use App\Http\Controllers\Controller;
use App\Http\Requests\FoundPets\ListFoundPetsRequest;
use App\Http\Requests\FoundPets\SaveFoundPetRequest;
use App\Http\Resources\FoundPetResource;
use App\Models\FoundPetReport;
use App\Services\FoundPets\FoundPetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FoundPetController extends Controller
{
    public function index(ListFoundPetsRequest $request): AnonymousResourceCollection
    {
        // Hide reports from accounts that are no longer active or verified.
        $query = FoundPetReport::query()->whereHas('user', fn (Builder $user) =>
            $user->where('is_active', true)->whereNotNull('email_verified_at'));

        return $this->listing($request, $query);
    }

    public function mine(ListFoundPetsRequest $request): AnonymousResourceCollection
    {
        return $this->listing($request, FoundPetReport::query()->where('user_id', $request->user()->id));
    }

    public function store(SaveFoundPetRequest $request, FoundPetService $service): JsonResponse
    {
        $report = $service->create($request->user(), $request->validated(), $request->file('photos'));

        return (new FoundPetResource($report))
            ->additional(['message' => 'Found pet report created successfully.'])
            ->response()->setStatusCode(201);
    }

    public function show(FoundPetReport $foundPet): FoundPetResource
    {
        abort_unless($foundPet->user()->where('is_active', true)->whereNotNull('email_verified_at')->exists(), 404);

        return new FoundPetResource($foundPet->load('photos'));
    }

    public function update(SaveFoundPetRequest $request, FoundPetReport $foundPet, FoundPetService $service): FoundPetResource
    {
        // Ownership is checked by SaveFoundPetRequest before validation.
        return (new FoundPetResource($service->update($foundPet, $request->validated())))
            ->additional(['message' => 'Found pet report updated successfully.']);
    }

    public function destroy(FoundPetReport $foundPet, FoundPetService $service): JsonResponse
    {
        Gate::authorize('delete', $foundPet);
        $service->delete($foundPet);

        return response()->json(['message' => 'Found pet report deleted successfully.']);
    }

    public function status(Request $request, FoundPetReport $foundPet, FoundPetService $service): FoundPetResource
    {
        Gate::authorize('update', $foundPet);
        $data = $request->validate(['status' => ['required', Rule::in(FoundPetReport::STATUSES)]]);

        return (new FoundPetResource($service->changeStatus($foundPet, $data['status'])))
            ->additional(['message' => 'Report status updated successfully.']);
    }

    public function addPhotos(Request $request, FoundPetReport $foundPet, FoundPetService $service): FoundPetResource
    {
        Gate::authorize('update', $foundPet);
        $request->validate(SaveFoundPetRequest::photoRules());

        return (new FoundPetResource($service->addPhotos($foundPet, $request->file('photos'))))
            ->additional(['message' => 'Photos added successfully.']);
    }

    public function deletePhoto(FoundPetReport $foundPet, int $photo, FoundPetService $service): JsonResponse
    {
        Gate::authorize('update', $foundPet);
        $service->deletePhoto($foundPet, $photo);

        return response()->json(['message' => 'Photo deleted successfully.']);
    }

    private function listing(ListFoundPetsRequest $request, Builder $query): AnonymousResourceCollection
    {
        $data = $request->validated();
        foreach (['pet_type', 'gender', 'status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        foreach (['breed' => 'breed', 'color' => 'color', 'location' => 'location_name'] as $key => $column) {
            if (isset($data[$key])) {
                // Column names are fixed above; all user input is bound as a value.
                $query->whereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($data[$key]).'%']);
            }
        }
        if (isset($data['q'])) {
            $term = '%'.mb_strtolower($data['q']).'%';
            $query->where(function (Builder $search) use ($term) {
                foreach (['pet_name', 'breed', 'color', 'description', 'distinguishing_features', 'location_name'] as $column) {
                    $search->orWhereRaw('LOWER('.$column.') LIKE ?', [$term]);
                }
            });
        }

        return FoundPetResource::collection($query->with('photos')->orderByDesc('created_at')
            ->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 15))->withQueryString());
    }
}