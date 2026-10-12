<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LostPetResource;
use App\Models\LostPetReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class AdminLostPetController extends Controller
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

        $query = LostPetReport::query()->with(['photos', 'user:id,name,email']);

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
            // Case-insensitive search that works on BOTH SQLite (tests) and PostgreSQL (prod).
            // Wrap each column in LOWER() and compare against the lower-cased search term.
            $term = '%'.mb_strtolower($data['q']).'%';
            $query->where(function (Builder $s) use ($term) {
                foreach (['pet_name', 'breed', 'color', 'location_name', 'description'] as $col) {
                    $s->orWhereRaw('LOWER('.$col.') LIKE ?', [$term]);
                }
            });
        }

        return LostPetResource::collection(
            $query->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    public function show(LostPetReport $lostPet): LostPetResource
    {
        return new LostPetResource($lostPet->load(['photos', 'user:id,name,email']));
    }

    public function destroy(LostPetReport $lostPet): JsonResponse
    {
        $paths = $lostPet->photos()->pluck('path')->all();
        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }
        $lostPet->delete();

        return response()->json(['message' => 'Lost pet report removed.']);
    }

    public function flag(Request $request, LostPetReport $lostPet): LostPetResource
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $lostPet->flagged_at = now();
        $lostPet->flag_reason = $data['reason'];
        $lostPet->save();

        return (new LostPetResource($lostPet->load(['photos', 'user:id,name,email'])))
            ->additional(['message' => 'Report flagged.']);
    }

    public function unflag(LostPetReport $lostPet): LostPetResource
    {
        $lostPet->flagged_at = null;
        $lostPet->flag_reason = null;
        $lostPet->save();

        return (new LostPetResource($lostPet->load(['photos', 'user:id,name,email'])))
            ->additional(['message' => 'Report unflagged.']);
    }
}