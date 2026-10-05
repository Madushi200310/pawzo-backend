<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pet\ReportPetRequest;
use App\Models\Pet;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ReportPetController extends Controller
{
    public function store(ReportPetRequest $request, Pet $pet): JsonResponse
    {
        // Cannot report your own pet
        if ($pet->user_id === $request->user()->id) {
            throw ValidationException::withMessages([
                'pet' => ['You cannot report your own pet.'],
            ]);
        }

        // Cannot report the same pet twice
        $already = $pet->reports()
            ->where('reporter_id', $request->user()->id)
            ->where('status', 'pending')
            ->exists();

        if ($already) {
            throw ValidationException::withMessages([
                'pet' => ['You already have a pending report for this pet.'],
            ]);
        }

        $report = $pet->reports()->create([
            'reporter_id' => $request->user()->id,
            'reason'      => $request->input('reason'),
            'notes'       => $request->input('notes'),
        ]);

        return response()->json([
            'message' => 'Report submitted successfully. Our team will review it.',
            'report'  => $report,
        ], 201);
    }
}