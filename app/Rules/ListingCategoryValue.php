<?php

namespace App\Rules;

use App\Models\ListingCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ListingCategoryValue implements ValidationRule
{
    public function __construct(
        private string $group,
        private ?string $currentValue = null,
        private bool $activeOnly = true,
    ) {}

    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (! is_string($value) || trim($value) === '') {
            $fail('The selected category is invalid.');

            return;
        }

        // Pet profiles historically use values such as "Dog" and "Cat".
        $candidate = $this->group === 'pet'
            ? mb_strtolower($value)
            : $value;

        $current = $this->currentValue;

        if ($current !== null && $this->group === 'pet') {
            $current = mb_strtolower($current);
        }

        if ($current !== null && $candidate === $current) {
            return;
        }

        $query = ListingCategory::query()
            ->where('group', $this->group)
            ->where('value', $candidate);

        if ($this->activeOnly) {
            $query->where('is_active', true);
        }

        if (! $query->exists()) {
            $fail('The selected category is unavailable.');
        }
    }
}