<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVaccinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vaccine_name'  => ['sometimes', 'required', 'string', 'max:150'],
            'given_date'    => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'next_due_date' => ['sometimes', 'nullable', 'date'],
            'vet_name'      => ['sometimes', 'nullable', 'string', 'max:100'],
            'batch_number'  => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes'         => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}