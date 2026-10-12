<?php

namespace App\Http\Requests\Rentals;

use App\Models\PetRentalRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondPetRentalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['accepted', 'rejected'])],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'owner_response' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'requester_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'pet_rental_id' => ['prohibited'],
        ];
    }
}
