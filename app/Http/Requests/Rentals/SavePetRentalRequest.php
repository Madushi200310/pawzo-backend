<?php

namespace App\Http\Requests\Rentals;

use App\Models\PetRental;
use App\Rules\ListingCategoryValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePetRentalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rental = $this->route('petRental');

        return $rental instanceof PetRental
            ? ($this->user()?->can('update', $rental) ?? false)
            : $this->user() !== null
                && $this->user()->is_active
                && $this->user()->email_verified_at !== null;
    }

    public function rules(): array
    {
        $rental = $this->route('petRental');
        $updating = $rental instanceof PetRental;
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        $rules = [
            'pet_name' => [...$required, 'string', 'max:120'],
            'pet_type' => [
                ...$required,
                'string',
                'max:30',
                new ListingCategoryValue(
                    'rental',
                    $updating ? $rental->pet_type : null,
                ),
            ],
            'breed' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => [...$required, Rule::in(PetRental::GENDERS)],
            'age' => ['sometimes', 'nullable', 'string', 'max:50'],
            'color' => ['sometimes', 'nullable', 'string', 'max:100'],
            'age_description' => ['sometimes', 'nullable', 'string', 'max:100'],
            'characteristics' => [
                'sometimes', 'nullable', 'string', 'max:2000',
            ],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:200'],
            'contact_phone' => [
                'sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{9,15}$/',
            ],
            'price_per_day' => [
                ...$required, 'numeric', 'min:0.01', 'max:100000',
            ],
            'location_name' => [...$required, 'string', 'max:255'],
            'latitude' => [...$required, 'numeric', 'between:-90,90'],
            'longitude' => [...$required, 'numeric', 'between:-180,180'],
            'description' => [...$required, 'string', 'max:5000'],
            'available_from' => [...$required, 'date', 'after_or_equal:today'],
            'available_to' => [...$required, 'date', 'after:available_from'],
            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'id' => ['prohibited'],
        ];

        if ($updating) {
            $rules['latitude'] = [
                'required_with:longitude', 'numeric', 'between:-90,90',
            ];
            $rules['longitude'] = [
                'required_with:latitude', 'numeric', 'between:-180,180',
            ];
            $rules['photos'] = ['prohibited'];
        } else {
            $rules += self::photoRules();
        }

        return $rules;
    }

    public static function photoRules(): array
    {
        return [
            'photos' => [
                'required', 'array', 'min:1', 'max:'.PetRental::MAX_PHOTOS,
            ],
            'photos.*' => [
                'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp',
                'extensions:jpg,jpeg,png,webp', 'max:5120',
                'dimensions:max_width=6000,max_height=6000',
            ],
        ];
    }
}