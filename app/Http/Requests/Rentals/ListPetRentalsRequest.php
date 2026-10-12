<?php

namespace App\Http\Requests\Rentals;

use App\Models\PetRental;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPetRentalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'filled', 'string', 'max:100'],
            'pet_type' => ['sometimes', 'required', Rule::in(PetRental::TYPES)],
            'gender' => ['sometimes', 'required', Rule::in(PetRental::GENDERS)],
            'status' => ['sometimes', 'required', Rule::in(PetRental::STATUSES)],
            'breed' => ['sometimes', 'filled', 'string', 'max:100'],
            'location' => ['sometimes', 'filled', 'string', 'max:255'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'gte:min_price'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
