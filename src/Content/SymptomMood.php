<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Maps a 0–10 symptom level to a mood emoji, oriented by the symptom's direction
 * so the face always reflects *goodness*, not the raw number. For a "higher is
 * worse" symptom (joint pain), level 0 is the best outcome (😁) and 10 the worst
 * (😩); for a "higher is better" symptom (energy), it's flipped. The middle (5)
 * is the neutral default 😐.
 *
 * The ramp is the "Gentle & warm" set: softer negative faces (no screaming) and a
 * clear, easy-to-read grin 😁 at the most-positive end — chosen because the user
 * base skews older and the previous top face (star-struck 🤩) was hard to interpret.
 *
 * Surfaced in Twig via `symptom_emoji(level, higherBetter)` (initial render) and
 * `symptom_mood_ramp()` (the array the slider's live JS indexes into) — one source
 * of truth for both, so server and client can't drift.
 */
final class SymptomMood
{
    /** Worst → best, indexed by "goodness" 0–10. */
    public const RAMP = ['😩', '😟', '😞', '😔', '😕', '😐', '🙂', '😌', '😊', '😄', '😁'];

    public static function forLevel(int $level, bool $higherBetter): string
    {
        $goodness = $higherBetter ? $level : 10 - $level;
        $goodness = max(0, min(10, $goodness));

        return self::RAMP[$goodness];
    }

    /** @return list<string> */
    public static function ramp(): array
    {
        return self::RAMP;
    }
}
