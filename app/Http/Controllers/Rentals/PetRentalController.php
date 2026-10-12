<?php

namespace App\Http\Controllers\Rentals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rentals\ListPetRentalsRequest;
use App\Http\Requests\Rentals\SavePetRentalRequest;
use App\Http\Resources\PetRentalResource;
use App\Models\PetRental;
use App\Services\Rentals\PetRentalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PetRentalController extends Controller
{
    public function index(ListPetRentalsRequest $request): AnonymousResourceCollection
    {
        $query = PetRental::query()->whereHas('user', fn (Builder $user) =>
            $user->where('is_active', true)->whereNotNull('email_verified_at'));

        return $this->listing($request, $query);
    }

    public function mine(ListPetRentalsRequest $request): AnonymousResourceCollection
    {
        return $this->listing($request, PetRental::query()->where('user_id', $request->user()->id));
    }

    public function store(SavePetRentalRequest $request, PetRentalService $service): JsonResponse
    {
        $petRental = $service->create($request->user(), $request->validated(), $request->file('photos'));

        return (new PetRentalResource($petRental))
            ->additional(['message' => 'Pet rental listing created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(PetRental $petRental): PetRentalResource
    {
        abort_unless($petRental->user()->where('is_active', true)->whereNotNull('email_verified_at')->exists(), 404);

        return new PetRentalResource($petRental->load('photos'));
    }

    public function update(SavePetRentalRequest $request, PetRental $petRental, PetRentalService $service): PetRentalResource
    {
        return (new PetRentalResource($service->update($petRental, $request->validated())))
            ->additional(['message' => 'Pet rental listing updated successfully.']);
    }

    public function destroy(PetRental $petRental, PetRentalService $service): JsonResponse
    {
        Gate::authorize('delete', $petRental);
        $service->delete($petRental);

        return response()->json(['message' => 'Pet rental listing deleted successfully.']);
    }

    public function addPhotos(Request $request, PetRental $petRental, PetRentalService $service): PetRentalResource
    {
        Gate::authorize('update', $petRental);
        $request->validate(SavePetRentalRequest::photoRules());

        return (new PetRentalResource($service->addPhotos($petRental, $request->file('photos'))))
            ->additional(['message' => 'Photos added successfully.']);
    }

    public function deletePhoto(PetRental $petRental, int $photo, PetRentalService $service): JsonResponse
    {
        Gate::authorize('update', $petRental);
        $service->deletePhoto($petRental, $photo);

        return response()->json(['message' => 'Photo deleted successfully.']);
    }

    private function listing(ListPetRentalsRequest $request, Builder $query): AnonymousResourceCollection
    {
        $data = $request->validated();

        foreach (['pet_type', 'gender', 'status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        foreach (['breed' => 'breed', 'location' => 'location_name'] as $key => $column) {
            if (isset($data[$key])) {
                $query->whereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($data[$key]).'%']);
            }
        }

        if (isset($data['min_price'])) {
            $query->where('price_per_day', '>=', (float) $data['min_price']);
        }

        if (isset($data['max_price'])) {
            $query->where('price_per_day', '<=', (float) $data['max_price']);
        }

        if (isset($data['q'])) {
            $term = '%'.mb_strtolower($data['q']).'%';
            $query->where(function (Builder $search) use ($term) {
                foreach (['pet_name', 'breed', 'description', 'location_name'] as $column) {
                    $search->orWhereRaw('LOWER('.$column.') LIKE ?', [$term]);
                }
            });
        }

        return PetRentalResource::collection(
            $query->with('photos')->orderByDesc('created_at')->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))->withQueryString()
        );
    }
}
