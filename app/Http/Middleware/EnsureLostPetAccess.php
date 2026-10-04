<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLostPetAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account is deactivated.'], 403);
        }

        // The current User model does not implement MustVerifyEmail.
        if (! $user->email_verified_at) {
            return response()->json(['message' => 'Please verify your email first.'], 403);
        }

        return $next($request);
    }
}