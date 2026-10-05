<?php

namespace App\Http\Controllers\Maps;

use App\Http\Controllers\Controller;
use App\Http\Requests\Maps\MapReportsRequest;
use App\Services\Maps\MapReportsService;
use Illuminate\Http\JsonResponse;

class MapReportsController extends Controller
{
    public function __invoke(
        MapReportsRequest $request,
        MapReportsService $service
    ): JsonResponse {
        return response()->json(
            $service->search($request->validated()),
            200,
            [
                'Content-Type' => 'application/geo+json',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }
}