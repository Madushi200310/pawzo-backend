<?php

namespace App\Http\Requests\Health;

use App\Models\HealthRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHealthRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'record_type'     => ['required', Rule::in(HealthRecord::TYPES)],
            'title'           => ['required', 'string', 'max:150'],
            'description'     => ['nullable', 'string', 'max:5000'],
            'date'            => ['nullable', 'date'],

            'vet_name'        => ['nullable', 'string', 'max:100'],
            'clinic_name'     => ['nullable', 'string', 'max:150'],

            'medication_name' => ['nullable', 'string', 'max:150'],
            'dosage'          => ['nullable', 'string', 'max:100'],
            'start_date'      => ['nullable', 'date'],
            'end_date'        => ['nullable', 'date', 'after_or_equal:start_date'],

            'is_ongoing'      => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $type = $this->input('record_type');

            if ($type === 'medication') {
                if (! $this->input('medication_name')) {
                    $v->errors()->add('medication_name', 'Medication name is required for medication records.');
                }
                if (! $this->input('start_date')) {
                    $v->errors()->add('start_date', 'Start date is required for medication records.');
                }
            }

            if ($type === 'vet_visit') {
                if (! $this->input('vet_name')) {
                    $v->errors()->add('vet_name', 'Vet name is required for vet visit records.');
                }
            }
        });
    }
}