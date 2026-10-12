<?php

namespace App\Http\Requests\Rentals;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePetRentalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->is_active && ! is_null($user->email_verified_at);
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:200'],
            'requester_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'pet_rental_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
