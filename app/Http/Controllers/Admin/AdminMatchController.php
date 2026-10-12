<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PetMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminMatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'min_score' => ['sometimes', 'numeric', 'between:0,100'],
            'flagged' => ['sometimes', 'boolean'],
            'lost_id' => ['sometimes', 'integer', 'min:1'],
            'found_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = PetMatch::query();

        if (isset($data['min_score'])) {
            $query->where('score', '>=', $data['min_score']);
        }
        if (isset($data['flagged'])) {
            $data['flagged'] ? $query->whereNotNull('flagged_at')
                             : $query->whereNull('flagged_at');
        }
        if (isset($data['lost_id'])) {
            $query->where('lost_pet_report_id', $data['lost_id']);
        }
        if (isset($data['found_id'])) {
            $query->where('found_pet_report_id', $data['found_id']);
        }

        $paginated = $query->orderByDesc('score')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20))
            ->withQueryString();

        return response()->json($paginated);
    }

    public function show(PetMatch $match): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $match->id,
                'score' => (float) $match->score,
                'breakdown' => $match->breakdown ?? null,
                'lost_pet_report_id' => $match->lost_pet_report_id,
                'found_pet_report_id' => $match->found_pet_report_id,
                'flagged_at' => $match->flagged_at?->toISOString(),
                'flag_reason' => $match->flag_reason,
                'created_at' => $match->created_at?->toISOString(),
            ],
        ]);
    }

    public function flag(Request $request, PetMatch $match): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $match->flagged_at = now();
        $match->flag_reason = $data['reason'];
        $match->save();

        return response()->json([
            'message' => 'Match flagged.',
            'data' => [
                'id' => $match->id,
                'flagged_at' => $match->flagged_at->toISOString(),
                'flag_reason' => $match->flag_reason,
            ],
        ]);
    }

    public function unflag(PetMatch $match): JsonResponse
    {
        $match->flagged_at = null;
        $match->flag_reason = null;
        $match->save();

        return response()->json([
            'message' => 'Match unflagged.',
            'data' => ['id' => $match->id],
        ]);
    }
}