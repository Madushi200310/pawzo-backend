<?php

namespace App\Http\Requests\LostPets;

use App\Models\LostPetReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLostPetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('lostPet');

        return $report instanceof LostPetReport
            ? $this->user()->can('update', $report)
            : $this->user() !== null;
    }

    public function rules(): array
    {
        $updating = $this->route('lostPet') instanceof LostPetReport;
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $phone = ['string', 'regex:/^\+?[0-9]{9,15}$/'];

        $rules = [
            'pet_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pet_type' => [...$required, Rule::in(LostPetReport::TYPES)],
            'breed' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => [...$required, Rule::in(LostPetReport::GENDERS)],
            'color' => [...$required, 'string', 'max:100'],
            'age_description' => ['sometimes', 'nullable', 'string', 'max:100'],
            'distinguishing_features' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description' => [...$required, 'string', 'max:5000'],
            'lost_at' => [...$required, 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'location_name' => [...$required, 'string', 'max:255'],
            'latitude' => [...$required, 'numeric', 'between:-90,90'],
            'longitude' => [...$required, 'numeric', 'between:-180,180'],
            'contact_name' => [...$required, 'string', 'max:100'],
            'contact_phone' => [...$required, ...$phone],
            'whatsapp_number' => [...$required, ...$phone],
            // Ownership, status, and filenames must never come from this form.
            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'id' => ['prohibited'],
        ];

        if ($updating) {
            // Update both coordinates together to avoid a half-updated location.
            $rules['latitude'] = ['required_with:longitude', 'numeric', 'between:-90,90'];
            $rules['longitude'] = ['required_with:latitude', 'numeric', 'between:-180,180'];
            $rules['photos'] = ['prohibited'];
        } else {
            $rules += self::photoRules();
        }

        return $rules;
    }

    public static function photoRules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:'.LostPetReport::MAX_PHOTOS],
            'photos.*' => [
                'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp',
                'extensions:jpg,jpeg,png,webp', 'max:5120',
                'dimensions:max_width=6000,max_height=6000',
            ],
        ];
    }
}