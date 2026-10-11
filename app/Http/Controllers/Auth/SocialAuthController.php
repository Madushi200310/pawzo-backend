<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirect the user to Google's OAuth consent screen.
     */
    public function redirectToGoogle(): JsonResponse
    {
        $url = Socialite::driver('google')
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json([
            'message'      => 'Redirect to Google',
            'redirect_url' => $url,
        ]);
    }

    /**
     * Handle Google's callback.
     */
    public function handleGoogleCallback(Request $request): JsonResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to authenticate with Google.',
                'error'   => $e->getMessage(),
            ], 401);
        }

        // Find by google_id first, then by email
        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        if (! $user) {
            // Create a fresh user
            $user = User::create([
                'name'              => $googleUser->getName() ?: 'Google User',
                'email'             => $googleUser->getEmail(),
                'password'          => Hash::make(Str::random(32)), // random, login via Google only
                'google_id'         => $googleUser->getId(),
                'avatar'            => $googleUser->getAvatar(),
                'role'              => 'user',
                'is_active'         => true,
                'email_verified_at' => now(), // Google already verified the email
            ]);
        } else {
            // Link the Google account if not already linked
            if (! $user->google_id) {
                $user->update([
                    'google_id' => $googleUser->getId(),
                    'avatar'    => $user->avatar ?? $googleUser->getAvatar(),
                ]);
            }
        }

        // Block deactivated accounts
        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login via Google successful.',
            'user'    => $user->fresh(),
            'token'   => $token,
        ]);
    }
}