<?php

namespace App\Http\Requests\LostPets;

use App\Models\LostPetReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLostPetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'filled', 'string', 'max:100'],
            'pet_type' => ['sometimes', 'required', Rule::in(LostPetReport::TYPES)],
            'gender' => ['sometimes', 'required', Rule::in(LostPetReport::GENDERS)],
            'status' => ['sometimes', 'required', Rule::in(LostPetReport::STATUSES)],
            'breed' => ['sometimes', 'filled', 'string', 'max:100'],
            'color' => ['sometimes', 'filled', 'string', 'max:100'],
            'location' => ['sometimes', 'filled', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}