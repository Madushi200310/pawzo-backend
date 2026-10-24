<?php

namespace App\Http\Requests\Services;

use App\Models\PetService;
use App\Rules\ListingCategoryValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPetServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'filled', 'string', 'max:100'],
            'service_type' => [
                'sometimes',
                'required',
                'string',
                new ListingCategoryValue('service', activeOnly: false),
            ],
            'status' => ['sometimes', 'required', Rule::in(PetService::STATUSES)],
            'location' => ['sometimes', 'filled', 'string', 'max:255'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}