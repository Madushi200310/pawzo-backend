<?php

namespace App\Http\Requests\Pet;

use App\Models\PetReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportPetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(PetReport::REASONS)],
            'notes'  => ['nullable', 'string', 'max:2000'],
        ];
    }
}