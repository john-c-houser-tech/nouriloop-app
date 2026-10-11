<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * Turns a user's three 1–5 voice dials (humor, warmth, education) into a compact
 * instruction appended to the coaching-narrative system prompts (goal check-ins,
 * the glucose Pattern Coach, and the Predict "what if" narratives).
 *
 * Style only: the snippet ends with a hard guardrail so it can never override the
 * facts, the plain-prose/no-Markdown rule, or the educational-not-medical-advice
 * framing the base prompts already establish. Level 3 across the board is the
 * neutral default — it still emits a line (one consistent mechanism), describing
 * today's balanced voice.
 *
 * Include {@see signature()} in any cache key for a narrative so changing the dials
 * busts the cache (e.g. the glucose coach narrative cache).
 */
final class TonePromptBuilder
{
    /** @var array<int, string> */
    private const HUMOR = [
        1 => 'dry and understated, at most the faintest flicker of wit',
        2 => 'mostly straight, with the occasional light touch',
        3 => 'a naturally conversational amount of humor',
        4 => 'noticeably playful and quippy',
        5 => 'wildly funny and irreverent, riffing like an improv comedian — but never at the cost of clarity',
    ];

    /** @var array<int, string> */
    private const WARMTH = [
        1 => 'clinical and neutral, just the facts',
        2 => 'professional but personable',
        3 => 'friendly and approachable',
        4 => 'warm and encouraging',
        5 => 'deeply nurturing and affectionate, like a doting grandparent',
    ];

    /** @var array<int, string> */
    private const EDUCATION = [
        1 => 'the simplest plain words, assuming no background at all',
        2 => 'simple everyday language',
        3 => 'clear language for a general adult reader',
        4 => 'comfortable with technical terms and detail',
        5 => 'expert-level and precise, assuming strong scientific literacy',
    ];

    private const GUARDRAIL = 'This controls writing style only — never let it change the '
        . 'facts or numbers, the plain-prose (no Markdown) rule, or the framing that this is '
        . 'an educational wellness signal and not medical advice. If a playful or warm tone '
        . 'would undercut clarity or that framing, dial it back.';

    public function forUser(User $user): string
    {
        return $this->build(
            $user->getToneHumor(),
            $user->getToneWarmth(),
            $user->getToneEducation(),
        );
    }

    public function build(int $humor, int $warmth, int $education): string
    {
        $humor     = $this->clamp($humor);
        $warmth    = $this->clamp($warmth);
        $education = $this->clamp($education);

        return 'Voice and tone for this message — humor: ' . self::HUMOR[$humor] . '; '
            . 'warmth: ' . self::WARMTH[$warmth] . '; '
            . 'reading level: ' . self::EDUCATION[$education] . '. '
            . self::GUARDRAIL;
    }

    /** A stable token of the three dials, for cache keys. */
    public function signature(User $user): string
    {
        return sprintf(
            'tone-%d-%d-%d',
            $user->getToneHumor(),
            $user->getToneWarmth(),
            $user->getToneEducation(),
        );
    }

    private function clamp(int $value): int
    {
        return max(1, min(5, $value));
    }
}
