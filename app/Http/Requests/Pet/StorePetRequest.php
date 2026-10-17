<?php

namespace App\Http\Requests\Pet;

use App\Rules\ListingCategoryValue;
use Illuminate\Foundation\Http\FormRequest;

class StorePetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => [
                'required',
                'string',
                'max:50',
                new ListingCategoryValue('pet'),
            ],
            'breed' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'in:male,female,unknown'],
            'date_of_birth' => [
                'nullable', 'date', 'before_or_equal:today',
            ],
            'color' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'characteristics' => ['nullable', 'string', 'max:2000'],
        ];
    }
}