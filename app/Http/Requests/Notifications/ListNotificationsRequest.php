<?php

namespace App\Http\Requests\Notifications;

use App\Models\Notification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::in(['read', 'unread'])],
            'type' => ['sometimes', 'required', Rule::in(Notification::TYPES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}