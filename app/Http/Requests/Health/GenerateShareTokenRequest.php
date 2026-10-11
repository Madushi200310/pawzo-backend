<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class GenerateShareTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'include'          => ['required', 'array'],
            'include.pet_details'      => ['nullable', 'boolean'],
            'include.vaccinations'     => ['nullable', 'boolean'],
            'include.medical_history'  => ['nullable', 'boolean'],
            'include.medications'      => ['nullable', 'boolean'],
            'include.characteristics'  => ['nullable', 'boolean'],

            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $include = $this->input('include', []);
            $anyTrue = collect($include)->filter()->count() > 0;

            if (! $anyTrue) {
                $v->errors()->add('include', 'At least one section must be included.');
            }
        });
    }
}