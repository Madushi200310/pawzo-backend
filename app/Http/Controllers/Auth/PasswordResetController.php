<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function forgot(
        Request $request,
        OtpService $otp,
    ): JsonResponse {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()
            ->where('email', $data['email'])
            ->where('is_active', true)
            ->first();

        if ($user) {
            try {
                $otp->send($user, 'password_reset');
            } catch (ValidationException $exception) {
                // Preserve the same response during the resend cooldown.
            }
        }

        return response()->json([
            'message' => 'If the account is eligible, a password reset code has been sent.',
        ]);
    }

    public function reset(
        Request $request,
        OtpService $otp,
    ): JsonResponse {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ]);

        $successful = DB::transaction(function () use ($data, $otp) {
            $user = User::query()
                ->where('email', $data['email'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (
                ! $user
                || ! $otp->verify(
                    $user,
                    'password_reset',
                    $data['code'],
                )
            ) {
                // Returning commits unsuccessful OTP attempt counts.
                return false;
            }

            $user->forceFill([
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();

            return true;
        });

        if (! $successful) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired reset code.'],
            ]);
        }

        return response()->json([
            'message' => 'Password reset successfully. Please log in again.',
        ]);
    }
}