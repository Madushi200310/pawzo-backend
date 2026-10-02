<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password'         => [
                'required',
                'confirmed',
                'different:current_password',
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'password.different' => 'New password must be different from the current password.',
        ];
    }
}
