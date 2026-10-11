<?php

declare(strict_types=1);

namespace App\Twig;

use App\Content\SymptomMood;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `symptom_emoji(level, higherBetter)` → the mood face for a symptom level, and
 * `symptom_mood_ramp()` → the full worst→best emoji array for the slider's live JS.
 * Both delegate to App\Content\SymptomMood so the server-rendered face and the
 * client-side one share a single definition.
 */
class SymptomMoodExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('symptom_emoji', SymptomMood::forLevel(...)),
            new TwigFunction('symptom_mood_ramp', SymptomMood::ramp(...)),
        ];
    }
}
