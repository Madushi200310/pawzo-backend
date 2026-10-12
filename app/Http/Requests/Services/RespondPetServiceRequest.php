<?php

namespace App\Http\Requests\Services;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondPetServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['approved', 'rejected', 'removed'])],
            'rejection_reason' => [
                'required_if:status,rejected',
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}