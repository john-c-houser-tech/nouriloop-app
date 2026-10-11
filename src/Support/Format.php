<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small formatting helpers shared across the goals feature so the same value
 * renders identically in the chat, the saved goal, the chart, and the email.
 */
final class Format
{
    /**
     * One decimal place, trailing zeros and a trailing dot trimmed: 30.0 → "30",
     * 28.5 → "28.5". Mirrors number_format's thousands separators (2300 → "2,300").
     */
    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
