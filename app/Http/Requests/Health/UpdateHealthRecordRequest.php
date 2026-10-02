<?php

namespace App\Http\Requests\Health;

use App\Models\HealthRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHealthRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'record_type'     => ['sometimes', 'required', Rule::in(HealthRecord::TYPES)],
            'title'           => ['sometimes', 'required', 'string', 'max:150'],
            'description'     => ['sometimes', 'nullable', 'string', 'max:5000'],
            'date'            => ['sometimes', 'nullable', 'date'],

            'vet_name'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'clinic_name'     => ['sometimes', 'nullable', 'string', 'max:150'],

            'medication_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'dosage'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'start_date'      => ['sometimes', 'nullable', 'date'],
            'end_date'        => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],

            'is_ongoing'      => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}