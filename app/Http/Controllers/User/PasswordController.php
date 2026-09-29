<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\ChangePasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Change the authenticated user's password.
     */
    public function update(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        // Social-login users may not have a password yet
        if (! $user->password) {
            return response()->json([
                'message' => 'Your account was created via social login. Please set a password first.',
            ], 422);
        }

        // Verify current password
        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // Update password
        $user->password = $request->password; // hashed cast handles hashing
        $user->save();

        // Revoke all OTHER tokens (keep current session logged in).
        // In tests, currentAccessToken() may be null (actingAs), so we guard it.
        $currentToken = $request->user()->currentAccessToken();

        if ($currentToken) {
            $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        } else {
            // No current token (e.g. in tests) → clear all tokens except none
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Password changed successfully. Other devices have been logged out.',
        ]);
    }
}