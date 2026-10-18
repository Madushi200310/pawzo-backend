<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMarketplaceSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $isListingCreation = $request->isMethod('POST')
            && in_array(
                $request->path(),
                ['api/products', 'api/pet-sales'],
                true,
            );

        if (! $isListingCreation) {
            return $next($request);
        }

        $user = $request->user() ?? auth('sanctum')->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (
            ! $user->is_active
            || $user->email_verified_at === null
        ) {
            return response()->json([
                'message' => 'An active, verified account is required.',
            ], 403);
        }

        if (! $user->businesses()->where('status', 'approved')->exists()) {
            return response()->json([
                'message' => 'An approved business is required to create marketplace listings.',
            ], 403);
        }

        return $next($request);
    }
}