<?php

namespace App\Http\Requests\Pet;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['sometimes', 'required', 'string', 'max:100'],
            'type'            => ['sometimes', 'required', 'string', 'max:50'],
            'breed'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender'          => ['sometimes', 'nullable', 'in:male,female,unknown'],
            'date_of_birth'   => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'color'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'weight'          => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99'],
            'characteristics' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}