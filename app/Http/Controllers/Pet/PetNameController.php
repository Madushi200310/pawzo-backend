<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pet\GeneratePetNamesRequest;
use App\Services\PetNameGeneratorService;
use Illuminate\Http\JsonResponse;

class PetNameController extends Controller
{
    public function generate(GeneratePetNamesRequest $request, PetNameGeneratorService $service): JsonResponse
    {
        $result = $service->generate(
            type:   $request->type,
            gender: $request->gender,
            color:  $request->color,
            style:  $request->style,
        );

        return response()->json([
            'message' => '20 pet names generated successfully.',
            'count'   => count($result['names']),
            'names'   => $result['names'],
        ]);
    }
}