<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PetRentalRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pet_rental_id' => $this->pet_rental_id,
            'pet_rental' => $this->whenLoaded('rental', fn () => new PetRentalResource($this->rental)),
            'requester_id' => $this->requester_id,
            'requester' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ]),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'message' => $this->message,
            'purpose' => $this->purpose,
            'owner_response' => $this->owner_response,
            'responded_at' => $this->responded_at?->toISOString(),
            'status' => $this->status,
            'is_owner' => (int) $this->rental?->user_id === (int) $request->user()?->id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
