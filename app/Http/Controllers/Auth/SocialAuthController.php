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
    // ============================================================
    //                       GOOGLE
    // ============================================================

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

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        if (! $user) {
            $user = User::create([
                'name'              => $googleUser->getName() ?: 'Google User',
                'email'             => $googleUser->getEmail(),
                'password'          => Hash::make(Str::random(32)),
                'google_id'         => $googleUser->getId(),
                'avatar'            => $googleUser->getAvatar(),
                'role'              => 'user',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]);
        } elseif (! $user->google_id) {
            $user->update([
                'google_id' => $googleUser->getId(),
                'avatar'    => $user->avatar ?? $googleUser->getAvatar(),
            ]);
        }

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

    // ============================================================
    //                      FACEBOOK
    // ============================================================

    public function redirectToFacebook(): JsonResponse
    {
        $url = Socialite::driver('facebook')
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json([
            'message'      => 'Redirect to Facebook',
            'redirect_url' => $url,
        ]);
    }

    public function handleFacebookCallback(Request $request): JsonResponse
    {
        try {
            $fbUser = Socialite::driver('facebook')->stateless()->user();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to authenticate with Facebook.',
                'error'   => $e->getMessage(),
            ], 401);
        }

        $user = User::where('facebook_id', $fbUser->getId())->first()
            ?? User::where('email', $fbUser->getEmail())->first();

        if (! $user) {
            $user = User::create([
                'name'              => $fbUser->getName() ?: 'Facebook User',
                'email'             => $fbUser->getEmail(),
                'password'          => Hash::make(Str::random(32)),
                'facebook_id'       => $fbUser->getId(),
                'avatar'            => $fbUser->getAvatar(),
                'role'              => 'user',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]);
        } elseif (! $user->facebook_id) {
            $user->update([
                'facebook_id' => $fbUser->getId(),
                'avatar'      => $user->avatar ?? $fbUser->getAvatar(),
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login via Facebook successful.',
            'user'    => $user->fresh(),
            'token'   => $token,
        ]);
    }
}