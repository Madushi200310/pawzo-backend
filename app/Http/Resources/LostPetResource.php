<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class LostPetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Explicit allowlist: do not serialize the User or expose account fields.
        return [
            'id' => $this->id,
            'is_owner' => (int) $this->user_id === (int) $request->user()?->id,
            'pet_name' => $this->pet_name,
            'pet_type' => $this->pet_type,
            'breed' => $this->breed,
            'gender' => $this->gender,
            'color' => $this->color,
            'age_description' => $this->age_description,
            'distinguishing_features' => $this->distinguishing_features,
            'description' => $this->description,
            'lost_at' => $this->lost_at->toISOString(),
            'location_name' => $this->location_name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'whatsapp_number' => $this->whatsapp_number,
            'status' => $this->status,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'url' => Storage::disk('public')->url($photo->path),
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}