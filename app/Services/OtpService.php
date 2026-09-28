<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public const EXPIRY_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Generate a new OTP, store it hashed, and email it to the user.
     */
    public function send(User $user, string $type): void
    {
        $latest = OtpCode::where('user_id', $user->id)
            ->where('type', $type)
            ->latest()
            ->first();

        if ($latest && $latest->created_at->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            throw ValidationException::withMessages([
                'email' => ['Please wait a minute before requesting another code.'],
            ]);
        }

        // Remove any older unused codes so only the newest one works
        OtpCode::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('used_at')
            ->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'user_id' => $user->id,
            'type' => $type,
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
        ]);

        Mail::to($user->email)->send(new OtpMail($code, $type, self::EXPIRY_MINUTES));
    }

    /**
     * Check a code. Returns true and marks it used if valid.
     */
    public function verify(User $user, string $type, string $code): bool
    {
        $otp = OtpCode::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        if (! Hash::check($code, $otp->code)) {
            $otp->increment('attempts');

            return false;
        }

        $otp->update(['used_at' => now()]);

        return true;
    }
}