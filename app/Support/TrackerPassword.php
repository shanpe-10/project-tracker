<?php

namespace App\Support;

final class TrackerPassword
{
    public static function rule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (!is_string($value) || strlen($value) > 72 || str_contains($value, "\0")) {
                $fail('Use a password of at most 72 bytes without null characters.');
            }
        };
    }
}
