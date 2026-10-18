<?php

namespace App\Http\Requests\Rentals;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondPetRentalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && $this->user()->is_active
            && $this->user()->email_verified_at !== null;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in(['accepted', 'rejected', 'cancelled']),
            ],
            'message' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
            'owner_response' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
                'prohibited_if:status,cancelled',
            ],
            'id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'pet_rental_id' => ['prohibited'],
            'start_date' => ['prohibited'],
            'end_date' => ['prohibited'],
            'responded_at' => ['prohibited'],
        ];
    }
}