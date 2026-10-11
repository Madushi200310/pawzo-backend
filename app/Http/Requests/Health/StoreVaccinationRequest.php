<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class StoreVaccinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vaccine_name'  => ['required', 'string', 'max:150'],
            'given_date'    => ['required', 'date', 'before_or_equal:today'],
            'next_due_date' => ['nullable', 'date', 'after:given_date'],
            'vet_name'      => ['nullable', 'string', 'max:100'],
            'batch_number'  => ['nullable', 'string', 'max:100'],
            'notes'         => ['nullable', 'string', 'max:2000'],
        ];
    }
}