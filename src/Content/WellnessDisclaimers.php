<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Humorous, rotating "this is not medical advice" disclaimers.
 *
 * Two sets so callers can pick by available space: LONG (a sentence or two, for
 * the page footer) and SHORT (one-liners, for tight UI spots). Every entry still
 * carries the core message — educational wellness tool, not medical advice or
 * diagnosis — so any one of them is a valid disclaimer on its own. The canonical
 * *serious* disclaimers in the high-stakes contexts (inflammation score, glucose
 * insights, Predict, goals) are unchanged; this is supplementary, not a swap.
 *
 * Surfaced in Twig via the `wellness_disclaimer()` function (App\Twig\DisclaimerExtension).
 */
final class WellnessDisclaimers
{
    public const LONG = 'long';
    public const SHORT = 'short';

    /** @var list<string> Sentence-length, kept ≤ ~150 chars so the footer holds two lines on desktop. */
    private const LONG_SET = [
        'NouriLoop is a wellness tool, not a doctor — no medical school, no stethoscope, no business diagnosing you. Just patterns in your data.',
        "Think of NouriLoop as a friend who loves spreadsheets, not a physician. We spot patterns; we don't prescribe, diagnose, or excuse you from gym class.",
        'Educational wellness tool, not a medical device. If something looks alarming, the next step is "talk to a licensed human," not "panic productively."',
        'NouriLoop shows you patterns in your own data — that\'s it. Not a doctor, not a diagnosis, more like a very enthusiastic highlighter.',
        'Not medical advice. NouriLoop has opinions about your data the way a weather app has opinions about the sky — useful, but bring an umbrella.',
        'NouriLoop is a wellness companion, not a medical authority. It just reflects your own data back at you. Any resemblance to a diagnosis is coincidental.',
        "We're not medical professionals — we're pattern-spotters. The difference matters, especially to lawyers. Learn from us; don't skip your doctor.",
        'NouriLoop turns your data into "huh, interesting," not "you are hereby diagnosed." For the second one, consult someone with a waiting room.',
        'This is an educational tool wearing a lab coat it found at a thrift store. Patterns in your data — not medical advice. Real questions go to clinicians.',
        'NouriLoop notices things; it does not diagnose, treat, cure, or prevent them. Those verbs belong to your healthcare provider, who earned them.',
        "A wellness tool, not a medical device. NouriLoop is the friend who notices you've eaten a lot of cheese — not the cardiologist who explains why.",
        'NouriLoop offers insights, not verdicts — and definitely not the final word on anything happening inside you. Ask a professional for that.',
        'NouriLoop is wellness software, not a tiny doctor living in your phone. It reads your data, not your fate.',
        'Contains patterns, not prescriptions. If you came for a diagnosis, you\'ve wandered into the wrong building — ours has a "download" button.',
        'NouriLoop helps you understand your own data, gently and without a medical degree. It\'s a "have you considered…?", not a diagnosis.',
        'Not your doctor, your nurse, or your slightly-too-confident uncle. Just your data, organized into patterns you might find useful.',
        'NouriLoop spots trends the way a metal detector spots beeps — informative, fun, occasionally a bottle cap. For answers, see a professional.',
        'Educational wellness software. Treat its insights like a horoscope that did its homework: interesting, suggestive, and not a reason to skip the doctor.',
        "NouriLoop helps you see your own data more clearly. It's not a medical device, and it'll be the first to admit it — real concerns go to clinicians.",
        'Not a diagnosis, not medical advice, not a tiny doctor in your phone — just your patterns, reflected back so you can ask better questions.',
    ];

    /** @var list<string> One-liners (~7–11 words) for tight spaces. */
    private const SHORT_SET = [
        "NouriLoop spots patterns, not problems — it's wellness, not a doctor.",
        "Educational wellness tool, not a medical device. We don't do diagnoses.",
        "We read your data, not your X-rays. Not medical advice.",
        'Insights, not diagnoses. For the real thing, see a real doctor.',
        'NouriLoop: a wellness tool with zero medical degrees and no shame about it.',
        'Patterns in your data — not medical advice, prescriptions, or prophecies.',
        'Not a doctor, not a diagnosis, just your data made readable.',
        'Wellness insights only. Symptoms go to a clinician, not a footer.',
        'NouriLoop informs; it does not diagnose. Big legal difference.',
        'Educational only — think highlighter, not stethoscope.',
        'We surface trends. Your doctor surfaces answers. Know the difference.',
        'Not medical advice — just your patterns, reflected back to you.',
        'NouriLoop is wellness software, not a tiny doctor in your phone.',
        'For learning, not diagnosing. Real concerns → real professionals.',
        'Your data, organized. Your diagnosis, not our department.',
        'A wellness tool, not a verdict on anything inside you.',
        "NouriLoop notices things. Diagnosing them is the doctor's job.",
        'Educational wellness insights — no diagnosis, no prescription, no white coat.',
        'We do patterns, not pathology. See a clinician for the rest.',
        'Not a medical device. Just your data, with a sense of humor.',
    ];

    /**
     * A random disclaimer from the requested set.
     *
     * @param self::LONG|self::SHORT $length
     */
    public static function random(string $length = self::LONG): string
    {
        $set = self::set($length);

        return $set[array_rand($set)];
    }

    /**
     * @param self::LONG|self::SHORT $length
     * @return list<string>
     */
    public static function set(string $length): array
    {
        return match ($length) {
            self::LONG  => self::LONG_SET,
            self::SHORT => self::SHORT_SET,
            default     => throw new \InvalidArgumentException(
                sprintf('Unknown disclaimer length "%s"; use "long" or "short".', $length),
            ),
        };
    }
}
