<?php

namespace App\Http\Requests\Reviews;

use App\Models\Business;
use App\Models\PetSaleListing;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveReviewRequest extends FormRequest
{
    public const TYPE_MAP = [
        'product' => Product::class,
        'business' => Business::class,
        'pet-sale' => PetSaleListing::class,
    ];

    public function authorize(): bool
    {
        $review = $this->route('review');

        if ($review instanceof \App\Models\Review) {
            return $this->user()?->can('update', $review) ?? false;
        }

        return $this->user() !== null
            && $this->user()->is_active
            && $this->user()->email_verified_at !== null;
    }

    public function rules(): array
    {
        $review = $this->route('review');
        $creating = ! ($review instanceof \App\Models\Review);

        if ($creating) {
            return [
                'type' => ['required', Rule::in(array_keys(self::TYPE_MAP))],
                'id' => ['required', 'integer', 'min:1'],
                'booking_reference' => ['required', 'string', 'max:100'],
                'rating' => ['required', 'integer', 'between:1,5'],
                'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ];
        }

        return [
            'rating' => ['sometimes', 'required', 'integer', 'between:1,5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'type' => ['prohibited'],
            'id' => ['prohibited'],
            'booking_reference' => ['prohibited'],
            'reviewable_type' => ['prohibited'],
            'reviewable_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'is_approved' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
        ];
    }

    public static function resolveType(string $alias): string
    {
        return self::TYPE_MAP[$alias] ?? abort(422, 'Invalid reviewable type.');
    }
}