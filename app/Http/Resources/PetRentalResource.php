<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PetRentalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_owner' => (int) $this->user_id === (int) $request->user()?->id,
            'pet_name' => $this->pet_name,
            'pet_type' => $this->pet_type,
            'breed' => $this->breed,
            'gender' => $this->gender,
            'age' => $this->age,
            'color' => $this->color,
            'age_description' => $this->age_description,
            'characteristics' => $this->characteristics,
            'purpose' => $this->purpose,
            'contact_phone' => $this->contact_phone,
            'price_per_day' => (float) $this->price_per_day,
            'location_name' => $this->location_name,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'description' => $this->description,
            'available_from' => $this->available_from?->toDateString(),
            'available_to' => $this->available_to?->toDateString(),
            'status' => $this->status,
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'url' => Storage::disk('public')->url($photo->path),
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
