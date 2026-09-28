<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, OtpService $otp): JsonResponse
    {
        // Password is hashed automatically by the 'hashed' cast on the User model
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => $request->password,
            'role' => 'user',
        ]);

        $otp->send($user, 'email_verification');

        return response()->json([
            'message' => 'Registration successful. Please check your email for the verification code.',
            'email' => $user->email,
        ], 201);
    }
}