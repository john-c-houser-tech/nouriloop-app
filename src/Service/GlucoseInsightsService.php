<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AnalyzedMeal;
use App\Dto\GlucoseInsights;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Repository\MealEntryRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Aggregates per-meal glucose responses into cross-meal insights: which meals raised
 * the user's glucose most and least, which most exceeded their nutritional estimate,
 * and headline averages over the window.
 *
 * Reuses {@see PostprandialResponseService} and {@see GlycemicImpactEstimator} per
 * meal, so an insight row is exactly what the meal-detail page shows — no second,
 * divergent computation. No Claude call and no persistence; everything is recomputed
 * at view time over readings the user already has. Window is bounded by the caller,
 * which keeps the per-meal glucose queries to a small, indexed set.
 *
 * The AI "Pattern Coach" is a deliberately separate, higher-cost tier, gated by BOTH
 * the global {@see $aiDeepDiveEnabled} env flag (a master kill switch) AND the Loop+
 * `ai_pattern_coach` feature — both must be on. Only then
 * does {@see forUser()} spend tokens to generate {@see GlucoseInsights::$coachingNarrative};
 * the free deterministic insights never incur token spend. Educational wellness
 * signal, not a medical measure.
 */
class GlucoseInsightsService
{
    /** How many meals to list in each ranking (top spikers, gentlest, above-expected). */
    public const TOP_N = 5;

    /** Output cap for the coaching narrative — a few short paragraphs. */
    private const COACH_MAX_TOKENS = 600;

    /** Safety expiry for a cached narrative; the key already changes when the data does. */
    private const COACH_CACHE_TTL = 86400;

    public function __construct(
        private readonly MealEntryRepository $meals,
        private readonly PostprandialResponseService $responses,
        private readonly GlycemicImpactEstimator $estimator,
        // Lazy: a non-AI page load (kill switch off, or user not entitled) must not
        // instantiate ClaudeClient or resolve ANTHROPIC_API_KEY. This service is also
        // used on the Dashboard, so the client is only materialised when the coach runs.
        #[Autowire(lazy: true)]
        private readonly ClaudeClient $claude,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly SubscriptionService $subscriptions,
        private readonly TonePromptBuilder $tone,
        private readonly LocalePromptBuilder $localePrompt,
        private readonly bool $aiDeepDiveEnabled = false,
    ) {
    }

    public function isAiDeepDiveEnabled(): bool
    {
        return $this->aiDeepDiveEnabled;
    }

    /**
     * Cross-meal insights over the half-open window [$from, $to) — pass the start of
     * the day after the last one to include (MealEntryRepository::findWithNutritionForRange
     * uses loggedAt < :to). Meals
     * without enough glucose readings to form a response curve are skipped; a window
     * with none yields an empty result (mealCount 0), rendered as a friendly empty state.
     */
    public function forUser(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): GlucoseInsights
    {
        $meals    = $this->meals->findWithNutritionForRange($user, $from, $to);
        $analyzed = $this->analyzeMeals($meals);

        // The AI Pattern Coach runs when BOTH the global kill switch and the user's
        // Loop+ feature are on. (The old per-user admin entitlement was retired
        // 2026-07-24 — services follow the tier; admins adjust the tier instead.)
        $aiAvailable = $this->aiDeepDiveEnabled
            && $this->subscriptions->hasFeature($user, 'ai_pattern_coach');

        $insights = $this->fromAnalyzed($analyzed, $aiAvailable);

        if ($aiAvailable && $insights->hasData()) {
            $insights = $insights->withCoachingNarrative($this->cachedCoaching($user, $insights));
        }

        return $insights;
    }

    /**
     * Analyzes a given set of meals (e.g. just today's, for the dashboard) into the
     * subset that has a glucose curve, each with its response and glycemic impact.
     * Ordered by meal time ascending, matching the input order.
     *
     * @param iterable<MealEntry> $meals
     * @return list<AnalyzedMeal>
     */
    public function analyzeMeals(iterable $meals): array
    {
        $meals = array_values(is_array($meals) ? $meals : iterator_to_array($meals, false));
        // One glucose query for the whole set, not one per meal.
        $responses = $this->responses->forMeals($meals);

        $analyzed = [];
        foreach ($meals as $i => $meal) {
            $response = $responses[$i] ?? null;
            if ($response === null || !$response->hasCurve) {
                continue;
            }

            $nutrition = $meal->getNutritionData();
            $impact    = $nutrition !== null ? $this->estimator->estimate($nutrition, $response) : null;

            $analyzed[] = new AnalyzedMeal($meal, $response, $impact);
        }

        return $analyzed;
    }

    /**
     * @param list<AnalyzedMeal> $analyzed
     * @param bool $aiAvailable whether the AI Pattern Coach is available to this user (global flag AND entitlement)
     */
    private function fromAnalyzed(array $analyzed, bool $aiAvailable): GlucoseInsights
    {
        $count = count($analyzed);
        if ($count === 0) {
            return new GlucoseInsights(0, null, null, [], [], [], $aiAvailable);
        }

        // delta and iauc are non-null here: every AnalyzedMeal has hasCurve === true.
        $deltas = array_map(static fn(AnalyzedMeal $a) => $a->response->delta, $analyzed);
        $iaucs  = array_map(static fn(AnalyzedMeal $a) => $a->response->iauc, $analyzed);
        $avgDelta = round(array_sum($deltas) / $count, 1);
        $avgIauc  = round(array_sum($iaucs) / $count, 1);

        // Biggest / smallest glucose rises. Sort copies so we don't disturb the
        // time-ordered input (and so each ranking is independent).
        $byDeltaDesc = $analyzed;
        usort($byDeltaDesc, static fn(AnalyzedMeal $a, AnalyzedMeal $b) => $b->response->delta <=> $a->response->delta);
        $topSpikers = array_slice($byDeltaDesc, 0, self::TOP_N);
        $gentlest   = array_slice(array_reverse($byDeltaDesc), 0, self::TOP_N);

        // Meals whose measured response most exceeded the nutritional estimate
        // (ratio > 1), highest ratio first. Only meals with a computable impact.
        $withImpact = array_values(array_filter(
            $analyzed,
            static fn(AnalyzedMeal $a) => $a->impact !== null && $a->impact->ratio > 1.0,
        ));
        usort($withImpact, static fn(AnalyzedMeal $a, AnalyzedMeal $b) => $b->impact->ratio <=> $a->impact->ratio);
        $aboveExpected = array_slice($withImpact, 0, self::TOP_N);

        return new GlucoseInsights(
            mealCount: $count,
            avgDelta: $avgDelta,
            avgIauc: $avgIauc,
            topSpikers: $topSpikers,
            gentlest: $gentlest,
            aboveExpected: $aboveExpected,
            aiDeepDiveEnabled: $aiAvailable,
        );
    }

    /**
     * Returns the coaching narrative for these insights, generating it via Claude only
     * on a cache miss. The cache key is the user plus a hash of the exact prompt input
     * (so it changes whenever the meals/window change, and is shared across page
     * refreshes, the back/forward buttons, and the day-window tabs that resolve to the
     * same data) — bounding token spend to roughly once per data change. Failures are
     * NOT cached, so a transient API error can succeed on the next view.
     */
    private function cachedCoaching(User $user, GlucoseInsights $insights): ?string
    {
        $message = $this->coachUserMessage($insights);
        $locale  = $user->getLocale();
        $tone    = $this->localePrompt->narrative($locale) . $this->tone->forUser($user);
        // Tone dials AND locale are part of the key so changing your voice or
        // language busts the cache and regenerates the narrative accordingly
        // (a language switch must not serve last language's cached narrative).
        $key = sprintf(
            'glucose_coach_%d_%s_%s_%s',
            $user->getId() ?? 0,
            $locale->value,
            $this->tone->signature($user),
            sha1($message),
        );

        // Non-English narratives get token headroom so the closing disclaimer
        // never truncates (~20-30% longer in es/de/ru).
        $maxTokens = $this->localePrompt->maxTokens($locale, self::COACH_MAX_TOKENS);

        return $this->cache->get(
            $key,
            function (ItemInterface $item, bool &$save) use ($message, $tone, $maxTokens): ?string {
                $item->expiresAfter(self::COACH_CACHE_TTL);
                $narrative = $this->generateCoaching($message, $tone, $maxTokens);
                $save = $narrative !== null; // don't cache a failed/empty generation

                return $narrative;
            },
        );
    }

    /**
     * Generates the AI Pattern Coach narrative from the user's own ranked glucose
     * data (passed as the prebuilt prompt message). Grounded strictly in that data —
     * no generic advice. Returns null on any failure (logged), so the page degrades to
     * a graceful notice rather than erroring (same fallback posture as GoalEvaluationService).
     */
    private function generateCoaching(string $message, string $tone, int $maxTokens = self::COACH_MAX_TOKENS): ?string
    {
        try {
            $result = $this->claude->complete(
                $this->coachSystemPrompt() . ' ' . $tone,
                [['role' => 'user', 'content' => $message]],
                $maxTokens,
            );

            $text = trim($result->text);

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Glucose AI coach generation failed: {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function coachSystemPrompt(): string
    {
        return <<<'PROMPT'
        You are NouriLoop's glucose Pattern Coach. You are given ONE user's own
        continuous-glucose meal responses over a recent window. Analyze ONLY this
        data: reference their actual meals and figures, and do not give generic
        nutrition advice, cite studies, or invent foods or numbers the data doesn't
        contain.

        Write 3 to 5 short paragraphs. Identify concrete patterns in how their meals
        affected glucose. Suggest likely causes grounded in those meals' ingredients
        and composition. Give 2-3 specific, actionable dietary adjustments drawn from
        their data (for example a pairing, swap, or timing change that references the
        meals shown).

        These are educational wellness signals, not medical advice or diagnosis. Do
        not diagnose or mention medication. Keep it warm, concise, and specific.
        Plain text only — no markdown headings or bullet characters.
        PROMPT;
    }

    private function coachUserMessage(GlucoseInsights $insights): string
    {
        $lines = [];
        $lines[] = sprintf(
            'Window summary: %d meals with a glucose curve. Average peak rise %s mg/dL, average iAUC %s mg/dL·min.',
            $insights->mealCount,
            (string) $insights->avgDelta,
            (string) $insights->avgIauc,
        );

        $lines[] = "\nBiggest glucose rises:";
        foreach ($insights->topSpikers as $meal) {
            $lines[] = $this->coachMealLine($meal);
        }

        $lines[] = "\nGentlest responses:";
        foreach ($insights->gentlest as $meal) {
            $lines[] = $this->coachMealLine($meal);
        }

        $lines[] = "\nRose more than their nutrition predicted:";
        if ($insights->aboveExpected === []) {
            $lines[] = '(none in this window)';
        } else {
            foreach ($insights->aboveExpected as $meal) {
                $lines[] = $this->coachMealLine($meal, withRatio: true);
            }
        }

        return implode("\n", $lines);
    }

    private function coachMealLine(AnalyzedMeal $a, bool $withRatio = false): string
    {
        $meal = $a->meal;
        $names = array_values(array_filter(array_map(
            static fn(array $i): string => trim((string) ($i['name'] ?? '')),
            $meal->getIngredients(),
        )));
        $ingredients = $names === [] ? 'unspecified' : implode(', ', array_slice($names, 0, 8));

        $line = sprintf(
            'Meal: %s (%s): +%s mg/dL peak, iAUC %s; ingredients: %s',
            $meal->getAiDescription() ?: 'meal',
            $meal->getMealType()?->label() ?? 'meal',
            (string) $a->response->delta,
            (string) $a->response->iauc,
            $ingredients,
        );

        if ($withRatio && $a->impact !== null) {
            $line .= sprintf(' — measured %.1f× the nutritional estimate (%s)', $a->impact->ratio, $a->impact->band);
        }

        return $line;
    }
}
