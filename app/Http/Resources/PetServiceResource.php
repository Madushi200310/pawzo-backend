<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PetServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_owner' => (int) $this->user_id === (int) $request->user()?->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'service_type' => $this->service_type,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'price_from' => $this->price_from,
            'price_to' => $this->price_to,
            'currency' => $this->currency,
            'location_name' => $this->location_name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'opening_hours' => $this->opening_hours,
            'status' => $this->status,
            'rejection_reason' => $this->status === 'rejected' ? $this->rejection_reason : null,
            'approved_at' => $this->approved_at?->toISOString(),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($p) => [
                'id' => $p->id,
                'url' => Storage::disk('public')->url($p->path),
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}