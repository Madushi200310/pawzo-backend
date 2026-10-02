<?php

namespace App\Http\Controllers\PublicAccess;

use App\Http\Controllers\Controller;
use App\Models\HealthShareToken;
use Illuminate\Http\JsonResponse;

class PublicHealthController extends Controller
{
    /**
     * Public, read-only view of a pet's shared health info.
     * No auth required — access controlled entirely by the share token.
     */
    public function show(string $token): JsonResponse
    {
        $share = HealthShareToken::with(['pet'])
            ->where('token', $token)
            ->first();

        if (! $share) {
            return response()->json(['message' => 'Invalid or expired link.'], 404);
        }

        if ($share->isExpired()) {
            return response()->json(['message' => 'This share link has expired.'], 410);
        }

        $pet = $share->pet;
        $include = $share->include;

        $payload = [
            'message' => 'Shared pet health information.',
            'shared_by' => [
                'owner_name' => $share->user->name ?? null,
            ],
            'generated_at' => $share->updated_at->toIso8601String(),
            'expires_at'   => $share->expires_at?->toIso8601String(),
        ];

        // ---- Pet details ----
        if (! empty($include['pet_details'])) {
            $payload['pet'] = [
                'name'          => $pet->name,
                'type'          => $pet->type,
                'breed'         => $pet->breed,
                'gender'        => $pet->gender,
                'date_of_birth' => $pet->date_of_birth?->toDateString(),
                'age'           => $pet->age(),
                'color'         => $pet->color,
                'weight'        => $pet->weight,
                'photo_url'     => $pet->photos()->where('is_primary', true)->first()?->path
                                    ? \Storage::disk('public')->url(
                                        $pet->photos()->where('is_primary', true)->first()->path
                                    )
                                    : null,
            ];
        }

        // ---- Characteristics ----
        if (! empty($include['characteristics'])) {
            $payload['characteristics'] = $pet->characteristics;
        }

        // ---- Vaccinations ----
        if (! empty($include['vaccinations'])) {
            $payload['vaccinations'] = $pet->vaccinations()
                ->orderByDesc('given_date')
                ->get()
                ->map(fn ($v) => [
                    'vaccine_name'  => $v->vaccine_name,
                    'given_date'    => $v->given_date->toDateString(),
                    'next_due_date' => $v->next_due_date?->toDateString(),
                    'vet_name'      => $v->vet_name,
                ])
                ->values();
        }

        // ---- Medical history (records of type medical_history, vet_visit, disease, treatment) ----
        if (! empty($include['medical_history'])) {
            $payload['medical_history'] = $pet->healthRecords()
                ->whereIn('record_type', ['medical_history', 'vet_visit', 'disease', 'treatment'])
                ->orderByDesc('date')
                ->get()
                ->map(fn ($r) => [
                    'record_type' => $r->record_type,
                    'title'       => $r->title,
                    'description' => $r->description,
                    'date'        => $r->date?->toDateString(),
                    'vet_name'    => $r->vet_name,
                ])
                ->values();
        }

        // ---- Medications ----
        if (! empty($include['medications'])) {
            $payload['medications'] = $pet->healthRecords()
                ->where('record_type', 'medication')
                ->orderByDesc('start_date')
                ->get()
                ->map(fn ($r) => [
                    'medication_name' => $r->medication_name,
                    'dosage'          => $r->dosage,
                    'start_date'      => $r->start_date?->toDateString(),
                    'end_date'        => $r->end_date?->toDateString(),
                    'is_ongoing'      => $r->is_ongoing,
                ])
                ->values();
        }

        // ---- Track view ----
        $share->increment('view_count');
        $share->update(['last_viewed_at' => now()]);

        return response()->json($payload);
    }
}