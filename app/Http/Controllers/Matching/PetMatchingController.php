<?php

namespace App\Http\Controllers\Matching;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoundPetResource;
use App\Http\Resources\LostPetResource;
use App\Models\FoundPetReport;
use App\Models\LostPetReport;
use App\Models\PetMatch;
use App\Services\Matching\SmartMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PetMatchingController extends Controller
{
    public function lost(
        Request $request,
        LostPetReport $lostPet
    ): JsonResponse {
        return $this->listing($request, $lostPet, true);
    }

    public function found(
        Request $request,
        FoundPetReport $foundPet
    ): JsonResponse {
        return $this->listing($request, $foundPet, false);
    }

    public function refreshLost(
        LostPetReport $lostPet,
        SmartMatchingService $service
    ): JsonResponse {
        Gate::authorize('update', $lostPet);

        return response()->json([
            'message' => 'Matches refreshed.',
            'count' => $service->refresh('lost', $lostPet->id),
        ]);
    }

    public function refreshFound(
        FoundPetReport $foundPet,
        SmartMatchingService $service
    ): JsonResponse {
        Gate::authorize('update', $foundPet);

        return response()->json([
            'message' => 'Matches refreshed.',
            'count' => $service->refresh('found', $foundPet->id),
        ]);
    }

    private function listing(
        Request $request,
        LostPetReport|FoundPetReport $report,
        bool $isLost
    ): JsonResponse {
        // Reuse the existing ownership policy.
        Gate::authorize('update', $report);

        $data = $request->validate([
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        $query = PetMatch::where(
            $isLost ? 'lost_pet_report_id' : 'found_pet_report_id',
            $report->id
        );

        foreach (
            ['lostReport' => 'lost', 'foundReport' => 'found']
            as $relation => $type
        ) {
            // Hide closed reports, ineligible users, and timestamp-stale matches.
            $query->whereHas($relation, function ($query) use ($type) {
                $query->where('status', 'open')
                    ->whereColumn(
                        $type.'_pet_reports.updated_at',
                        'pet_matches.'.$type.'_updated_at'
                    )
                    ->whereHas('user', function ($userQuery) {
                        $userQuery->where('is_active', true)
                            ->whereNotNull('email_verified_at');
                    });
            });
        }

        $page = $query
            ->with([
                'lostReport.photos',
                'foundReport.photos',
            ])
            ->orderByDesc('score')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 15)
            ->withQueryString();

        $items = $page->getCollection()->map(
            function ($match) use ($request, $isLost) {
                $other = $isLost
                    ? new FoundPetResource($match->foundReport)
                    : new LostPetResource($match->lostReport);

                return [
                    'id' => $match->id,
                    'score' => $match->score,
                    'details' => $match->details,
                    'calculated_at' =>
                        $match->updated_at->toISOString(),
                    'candidate_type' => $isLost ? 'found' : 'lost',
                    'candidate' => $other->resolve($request),
                ];
            }
        );

        return response()->json([
            'data' => $items,

            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'score_is_probability' => false,
            ],

            'links' => [
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }
}