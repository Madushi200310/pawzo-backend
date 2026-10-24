<?php

namespace App\Http\Requests\Pet;

use App\Models\Pet;
use App\Rules\ListingCategoryValue;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $pet = $this->route('pet');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'type' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                new ListingCategoryValue(
                    'pet',
                    $pet instanceof Pet ? $pet->type : null,
                ),
            ],
            'breed' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', 'in:male,female,unknown'],
            'date_of_birth' => [
                'sometimes', 'nullable', 'date', 'before_or_equal:today',
            ],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'weight' => [
                'sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99',
            ],
            'characteristics' => [
                'sometimes', 'nullable', 'string', 'max:2000',
            ],
        ];
    }
}