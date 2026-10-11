<?php

namespace App\Http\Requests\Pet;

use Illuminate\Foundation\Http\FormRequest;

class GeneratePetNamesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'   => ['required', 'string', 'max:50'],
            'gender' => ['required', 'in:male,female,unknown'],
            'color'  => ['nullable', 'string', 'max:50'],
            'style'  => ['required', 'string', 'in:Cute,Cool,Royal,Funny,Sinhala,English,Unique,Food-inspired'],
        ];
    }
}