<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OtpController extends Controller
{
    /**
     * Verify the 6-digit code sent after registration.
     */
    public function verifyEmail(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $otp->verify($user, 'email_verification', $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired code.'],
            ]);
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully.',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Resend the verification code.
     */
    public function resend(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Same response whether or not the account exists, so emails can't be probed
        if ($user && ! $user->email_verified_at) {
            $otp->send($user, 'email_verification');
        }

        return response()->json([
            'message' => 'If the account exists and is not verified, a new code has been sent.',
        ]);
    }
}