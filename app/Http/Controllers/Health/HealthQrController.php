<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\GenerateShareTokenRequest;
use App\Models\Pet;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HealthQrController extends Controller
{
    public function generate(GenerateShareTokenRequest $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $pet->shareTokens()->delete();

        $token = Str::random(64);

        $share = $pet->shareTokens()->create([
            'user_id'    => $request->user()->id,
            'token'      => $token,
            'include'    => $request->input('include'),
            'expires_at' => $request->input('expires_at'),
        ]);

        $publicUrl = url("/api/public/pets/{$token}");
        $qrBase64  = $this->generateQrBase64($publicUrl);

        return response()->json([
            'message'    => 'Health QR generated successfully.',
            'token'      => $token,
            'public_url' => $publicUrl,
            'qr_image'   => $qrBase64,
            'share'      => $share,
        ], 201);
    }

    public function show(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $share = $pet->shareTokens()->latest()->first();

        if (! $share || $share->isExpired()) {
            return response()->json([
                'message' => 'No active share token for this pet.',
                'share'   => null,
            ]);
        }

        $publicUrl = url("/api/public/pets/{$share->token}");
        $qrBase64  = $this->generateQrBase64($publicUrl);

        return response()->json([
            'message'    => 'Active share token fetched.',
            'token'      => $share->token,
            'public_url' => $publicUrl,
            'qr_image'   => $qrBase64,
            'share'      => $share,
        ]);
    }

    public function revoke(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $pet->shareTokens()->delete();

        return response()->json([
            'message' => 'Share token revoked successfully.',
        ]);
    }

    private function authorizeOwnership(Request $request, Pet $pet): void
    {
        if ($pet->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this pet.');
        }
    }

    /**
     * Generate a QR code PNG as a base64 data URI using BaconQrCode's GD renderer.
     */
    private function generateQrBase64(string $url): string
    {
        $renderer = new GDLibRenderer(400);
        $writer   = new Writer($renderer);

        $png = $writer->writeString($url);

        return 'data:image/png;base64,' . base64_encode($png);
    }
}