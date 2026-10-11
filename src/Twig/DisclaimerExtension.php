<?php

declare(strict_types=1);

namespace App\Twig;

use App\Content\WellnessDisclaimers;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `wellness_disclaimer('long'|'short')` — a random humorous "not medical advice"
 * disclaimer from App\Content\WellnessDisclaimers. Used in the footer (long set);
 * the short set is available for tight UI spots. Returns plain text (Twig escapes
 * it on output), so no `is_safe` flag.
 */
class DisclaimerExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('wellness_disclaimer', $this->wellnessDisclaimer(...)),
        ];
    }

    public function wellnessDisclaimer(string $length = WellnessDisclaimers::LONG): string
    {
        return WellnessDisclaimers::random($length);
    }
}
