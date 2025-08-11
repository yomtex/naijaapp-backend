<?php
namespace App\Modules\Auth\Rules;

use Illuminate\Contracts\Validation\Rule;

class StrongPin implements Rule
{
    protected string $message = 'The PIN is too weak. Choose a stronger one.';

    public function passes($attribute, $value): bool
    {
        if (!ctype_digit($value) || strlen($value) < 4) {
            $this->message = 'The PIN must contain only digits and be at least 4 digits long.';
            return false;
        }

        $length = strlen($value);
        $digits = str_split($value);

        // 1. Common weak PINs
        $weakPins = ['1234', '0000', '1111', '2222', '4321', '1212', '9999', '123456', '654321'];
        if (in_array($value, $weakPins)) {
            return false;
        }

        // 2. All same digit
        if (count(array_unique($digits)) === 1) {
            return false;
        }

        // 3. Ascending or descending
        $ascending = true;
        $descending = true;
        for ($i = 1; $i < $length; $i++) {
            if ((int)$digits[$i] !== (int)$digits[$i - 1] + 1) $ascending = false;
            if ((int)$digits[$i] !== (int)$digits[$i - 1] - 1) $descending = false;
        }
        if ($ascending || $descending) {
            return false;
        }

        // 4. Repeating pattern (e.g., 1212, 123123)
        if ($length % 2 === 0) {
            $half = $length / 2;
            if (substr($value, 0, $half) === substr($value, $half)) {
                return false;
            }
        }

        return true;
    }

    public function message(): string
    {
        return $this->message;
    }
}
