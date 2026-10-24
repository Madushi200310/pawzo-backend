<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoundPetResource;
use App\Models\FoundPetReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class AdminFoundPetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:open,reunited,closed'],
            'flagged' => ['sometimes', 'boolean'],
            'pet_type' => ['sometimes', 'string', 'max:30'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = FoundPetReport::query()->with(['photos', 'user:id,name,email']);

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['pet_type'])) {
            $query->where('pet_type', $data['pet_type']);
        }
        if (isset($data['flagged'])) {
            $data['flagged'] ? $query->whereNotNull('flagged_at')
                             : $query->whereNull('flagged_at');
        }
        if (isset($data['q'])) {
            $term = '%'.$data['q'].'%';
            $query->where(function (Builder $s) use ($term) {
                foreach (['pet_name', 'breed', 'color', 'location_name', 'description'] as $col) {
                    // LIKE is case-insensitive on SQLite (ASCII) and Postgres ILIKE-compatible via whereRaw fallback.
                    $s->orWhere($col, 'LIKE', $term);
                }
            });
        }

        return FoundPetResource::collection(
            $query->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    public function show(FoundPetReport $foundPet): FoundPetResource
    {
        return new FoundPetResource($foundPet->load(['photos', 'user:id,name,email']));
    }

    public function destroy(FoundPetReport $foundPet): JsonResponse
    {
        $paths = $foundPet->photos()->pluck('path')->all();
        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }
        $foundPet->delete();

        return response()->json(['message' => 'Found pet report removed.']);
    }

    public function flag(Request $request, FoundPetReport $foundPet): FoundPetResource
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $foundPet->flagged_at = now();
        $foundPet->flag_reason = $data['reason'];
        $foundPet->save();

        return (new FoundPetResource($foundPet->load(['photos', 'user:id,name,email'])))
            ->additional(['message' => 'Report flagged.']);
    }

    public function unflag(FoundPetReport $foundPet): FoundPetResource
    {
        $foundPet->flagged_at = null;
        $foundPet->flag_reason = null;
        $foundPet->save();

        return (new FoundPetResource($foundPet->load(['photos', 'user:id,name,email'])))
            ->additional(['message' => 'Report unflagged.']);
    }
}