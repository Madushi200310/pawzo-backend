<?php

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', Rule::in(array_keys(SaveReviewRequest::TYPE_MAP))],
            'id' => ['sometimes', 'required', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', Rule::in(['pending', 'approved', 'rejected', 'all'])],
            'min_rating' => ['sometimes', 'integer', 'between:1,5'],
            'max_rating' => ['sometimes', 'integer', 'between:1,5', 'gte:min_rating'],
            'q' => ['sometimes', 'filled', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}