<?php

namespace App\Http\Requests\Admin;

use App\Models\PetReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewPetReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'       => ['required', Rule::in(['reviewed', 'resolved', 'dismissed'])],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}