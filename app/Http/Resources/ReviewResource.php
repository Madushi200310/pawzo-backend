<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwner = $viewer && (int) $this->user_id === (int) $viewer->id;
        $isAdmin = $viewer && $viewer->role === 'admin';

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'reviewable_type' => class_basename($this->reviewable_type),
            'reviewable_id' => $this->reviewable_id,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'is_approved' => (bool) $this->is_approved,
            'rejection_reason' => ($isOwner || $isAdmin) ? $this->rejection_reason : null,
            'approved_at' => $this->approved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}