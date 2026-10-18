<?php

namespace App\Http\Requests\Services;

use App\Models\PetService;
use App\Rules\ListingCategoryValue;
use Illuminate\Foundation\Http\FormRequest;

class SavePetServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('petService');

        return $service instanceof PetService
            ? ($this->user()?->can('update', $service) ?? false)
            : $this->user() !== null
                && $this->user()->is_active
                && $this->user()->email_verified_at !== null;
    }

    public function rules(): array
    {
        $service = $this->route('petService');
        $updating = $service instanceof PetService;
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        $rules = [
            'name' => [...$required, 'string', 'max:150'],
            'service_type' => [
                ...$required,
                'string',
                'max:30',
                new ListingCategoryValue(
                    'service',
                    $updating ? $service->service_type : null,
                ),
            ],
            'description' => [...$required, 'string', 'max:5000'],
            'short_description' => [
                'sometimes', 'nullable', 'string', 'max:500',
            ],
            'price_from' => [...$required, 'numeric', 'min:0', 'max:9999999'],
            'price_to' => [
                'sometimes', 'nullable', 'numeric',
                'min:0', 'max:9999999', 'gte:price_from',
            ],
            'currency' => [
                'sometimes', 'string', 'size:3', 'in:LKR,USD,EUR,GBP,INR',
            ],
            'location_name' => [...$required, 'string', 'max:255'],
            'latitude' => [...$required, 'numeric', 'between:-90,90'],
            'longitude' => [...$required, 'numeric', 'between:-180,180'],
            'contact_phone' => [
                ...$required, 'string', 'regex:/^\+?[0-9]{9,15}$/',
            ],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'opening_hours' => ['sometimes', 'nullable', 'array'],
            'opening_hours.*' => [
                'sometimes', 'nullable', 'string', 'max:100',
            ],
            'user_id' => ['prohibited'],
            'business_id' => ['prohibited'],
            'slug' => ['prohibited'],
            'status' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'removed_at' => ['prohibited'],
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
                'required', 'array', 'min:1', 'max:'.PetService::MAX_PHOTOS,
            ],
            'photos.*' => [
                'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp',
                'extensions:jpg,jpeg,png,webp', 'max:5120',
                'dimensions:max_width=6000,max_height=6000',
            ],
        ];
    }
}