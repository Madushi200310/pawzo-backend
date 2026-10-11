<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AvatarController extends Controller
{
    /**
     * Upload or replace the authenticated user's avatar.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048', // 2 MB
            ],
        ]);

        $user = $request->user();

        // Delete the old avatar if it exists
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        // Store new avatar inside storage/app/public/avatars/{user_id}/
        $path = $request->file('avatar')->store("avatars/{$user->id}", 'public');

        // Save relative path (e.g. avatars/5/abc123.jpg) to DB
        $user->avatar = $path;
        $user->save();

        return response()->json([
            'message'   => 'Avatar uploaded successfully.',
            'avatar'    => $path,
            'avatar_url'=> Storage::disk('public')->url($path),
            'user'      => $user->fresh(),
        ]);
    }

    /**
     * Remove the authenticated user's avatar.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->avatar) {
            throw ValidationException::withMessages([
                'avatar' => ['No avatar to delete.'],
            ]);
        }

        if (Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->avatar = null;
        $user->save();

        return response()->json([
            'message' => 'Avatar removed successfully.',
            'user'    => $user->fresh(),
        ]);
    }
}
