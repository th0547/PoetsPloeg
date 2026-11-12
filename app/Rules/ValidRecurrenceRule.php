<?php

namespace App\Rules;

use App\Services\RecurrenceRule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Een eigen herhalingscode (RRULE) moet geldig zijn; zie RecurrenceRule::normalize(). */
class ValidRecurrenceRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Vul een geldige code in.');

            return;
        }
        try {
            RecurrenceRule::normalize($value);
        } catch (\InvalidArgumentException $e) {
            $fail($e->getMessage());
        }
    }
}
