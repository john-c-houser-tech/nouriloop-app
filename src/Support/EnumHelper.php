<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves a backed-enum from untrusted input (request params, a Claude proposal),
 * falling back to a default instead of throwing. Shared so every "string → enum"
 * site behaves the same — `Enum::from()` on a bad value throws an uncaught
 * \ValueError (a 500); this never does.
 */
final class EnumHelper
{
    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     * @param T               $default
     *
     * @return T
     */
    public static function resolve(string $enum, mixed $value, \BackedEnum $default): \BackedEnum
    {
        if (!is_string($value) && !is_int($value)) {
            return $default;
        }

        try {
            // Under strict_types, passing an int to a string-backed enum's tryFrom()
            // (or vice-versa) throws a TypeError rather than returning null — catch it
            // so a wrong-typed value falls back to the default instead of 500-ing.
            return $enum::tryFrom($value) ?? $default;
        } catch (\TypeError) {
            return $default;
        }
    }
}
