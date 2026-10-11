<?php

namespace App\Http\Requests\Maps;

use App\Models\LostPetReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MapReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'radius_km' => [
                'sometimes',
                'required',
                'numeric',
                'between:0.1,100',
            ],

            'kind' => [
                'sometimes',
                'required',
                Rule::in(['all', 'lost', 'found']),
            ],

            'pet_type' => [
                'sometimes',
                'required',
                Rule::in(LostPetReport::TYPES),
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in(LostPetReport::STATUSES),
            ],

            'limit' => [
                'sometimes',
                'required',
                'integer',
                'between:1,200',
            ],
        ];
    }
}