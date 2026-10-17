<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    public function redirectToGoogle(): JsonResponse
    {
        return $this->redirect('google', 'Google');
    }

    public function redirectToFacebook(): JsonResponse
    {
        return $this->redirect('facebook', 'Facebook');
    }

    public function handleGoogleCallback(Request $request): JsonResponse
    {
        return $this->callback('google', 'Google');
    }

    public function handleFacebookCallback(Request $request): JsonResponse
    {
        return $this->callback('facebook', 'Facebook');
    }

    private function redirect(string $provider, string $label): JsonResponse
    {
        $url = Socialite::driver($provider)
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json([
            'message' => "Redirect to {$label}",
            'redirect_url' => $url,
        ]);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'This account is unavailable. Please contact support.',
        ], 403);
    }

    private function callback(string $provider, string $label): JsonResponse
    {
        try {
            $socialUser = Socialite::driver($provider)
                ->stateless()
                ->user();
        } catch (Throwable) {
            return response()->json([
                'message' => "Failed to authenticate with {$label}.",
            ], 401);
        }

        $providerId = (string) $socialUser->getId();
        $email = $socialUser->getEmail();

        if ($providerId === '') {
            return response()->json([
                'message' => 'The provider did not return a valid account ID.',
            ], 422);
        }

        $column = $provider === 'google' ? 'google_id' : 'facebook_id';

        return DB::transaction(function () use (
            $socialUser,
            $providerId,
            $email,
            $column,
            $label,
        ) {
            $user = User::query()
                ->withTrashed()
                ->where($column, $providerId)
                ->lockForUpdate()
                ->first();

            if ($user && ($user->trashed() || ! $user->is_active)) {
                return $this->unavailable();
            }

            if (! $user) {
                if (
                    ! is_string($email)
                    || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                ) {
                    return response()->json([
                        'message' => 'The provider did not return a valid email address.',
                    ], 422);
                }

                $existing = User::query()
                    ->withTrashed()
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->trashed() || ! $existing->is_active) {
                        return $this->unavailable();
                    }

                    return response()->json([
                        'message' => 'An account already uses this email. Please use its existing login method.',
                        'error' => 'account_link_required',
                    ], 409);
                }

                $user = new User([
                    'name' => $socialUser->getName() ?: "{$label} User",
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                    $column => $providerId,
                    'avatar' => $socialUser->getAvatar(),
                    'role' => 'user',
                    'is_active' => true,
                ]);

                $user->forceFill([
                    'email_verified_at' => now(),
                ]);

                $user->save();
            }

            if (
                $user->email_verified_at === null
                && is_string($email)
                && strcasecmp($user->email, $email) === 0
            ) {
                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();
            }

            if ($user->email_verified_at === null) {
                return response()->json([
                    'message' => 'Please verify your email before logging in.',
                    'email_not_verified' => true,
                ], 403);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => "Login via {$label} successful.",
                'user' => $user->fresh(),
                'token' => $token,
            ]);
        });
    }
}