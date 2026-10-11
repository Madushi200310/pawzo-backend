<?php

namespace App\Http\Controllers\LostPets;

use App\Http\Controllers\Controller;
use App\Http\Requests\LostPets\ListLostPetsRequest;
use App\Http\Requests\LostPets\SaveLostPetRequest;
use App\Http\Resources\LostPetResource;
use App\Models\LostPetReport;
use App\Services\LostPets\LostPetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LostPetController extends Controller
{
    public function index(ListLostPetsRequest $request): AnonymousResourceCollection
    {
        // Hide reports from accounts that are no longer active or verified.
        $query = LostPetReport::query()->whereHas('user', fn (Builder $user) =>
            $user->where('is_active', true)->whereNotNull('email_verified_at'));

        return $this->listing($request, $query);
    }

    public function mine(ListLostPetsRequest $request): AnonymousResourceCollection
    {
        return $this->listing($request, LostPetReport::query()->where('user_id', $request->user()->id));
    }

    public function store(SaveLostPetRequest $request, LostPetService $service): JsonResponse
    {
        $report = $service->create($request->user(), $request->validated(), $request->file('photos'));

        return (new LostPetResource($report))
            ->additional(['message' => 'Lost pet report created successfully.'])
            ->response()->setStatusCode(201);
    }

    public function show(LostPetReport $lostPet): LostPetResource
    {
        abort_unless($lostPet->user()->where('is_active', true)->whereNotNull('email_verified_at')->exists(), 404);

        return new LostPetResource($lostPet->load('photos'));
    }

    public function update(SaveLostPetRequest $request, LostPetReport $lostPet, LostPetService $service): LostPetResource
    {
        // Ownership is checked by SaveLostPetRequest before validation.
        return (new LostPetResource($service->update($lostPet, $request->validated())))
            ->additional(['message' => 'Lost pet report updated successfully.']);
    }

    public function destroy(LostPetReport $lostPet, LostPetService $service): JsonResponse
    {
        Gate::authorize('delete', $lostPet);
        $service->delete($lostPet);

        return response()->json(['message' => 'Lost pet report deleted successfully.']);
    }

    public function status(Request $request, LostPetReport $lostPet, LostPetService $service): LostPetResource
    {
        Gate::authorize('update', $lostPet);
        $data = $request->validate(['status' => ['required', Rule::in(LostPetReport::STATUSES)]]);

        return (new LostPetResource($service->changeStatus($lostPet, $data['status'])))
            ->additional(['message' => 'Report status updated successfully.']);
    }

    public function addPhotos(Request $request, LostPetReport $lostPet, LostPetService $service): LostPetResource
    {
        Gate::authorize('update', $lostPet);
        $request->validate(SaveLostPetRequest::photoRules());

        return (new LostPetResource($service->addPhotos($lostPet, $request->file('photos'))))
            ->additional(['message' => 'Photos added successfully.']);
    }

    public function deletePhoto(LostPetReport $lostPet, int $photo, LostPetService $service): JsonResponse
    {
        Gate::authorize('update', $lostPet);
        $service->deletePhoto($lostPet, $photo);

        return response()->json(['message' => 'Photo deleted successfully.']);
    }

    private function listing(ListLostPetsRequest $request, Builder $query): AnonymousResourceCollection
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

        return LostPetResource::collection($query->with('photos')->orderByDesc('created_at')
            ->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 15))->withQueryString());
    }
}