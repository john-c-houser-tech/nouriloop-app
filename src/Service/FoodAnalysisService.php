<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AnalysisResult;
use App\Dto\ClaudeResult;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\AnalysisKind;
use App\Exception\PhotoLimitExceededException;

class FoodAnalysisService
{
    private const MAX_TOKENS = 2048;

    /**
     * User-notes cap, shared with the form's Length constraint — notes are
     * interpolated into a billable prompt, so the service also enforces it
     * defensively (a caller bypassing the form can't inflate the request).
     */
    public const NOTES_MAX_LENGTH = 1000;

    private const BASE_PROMPT = <<<'PROMPT'
Analyze this food image and return a JSON object with nutritional estimates.
Be as accurate as possible based on visible portion sizes and food types.
If multiple items are visible, sum all values into totals for the entire meal.

If the image is a nutrition-facts label or food packaging rather than prepared
food, base EVERY nutrient value on a SINGLE serving as printed (the "Amount per
serving" column) — do NOT multiply by the "servings per container", even when the
label also lists per-container totals. Note the serving size in the description
(e.g. "per 1 cup (240 ml) serving"). When the packaging shows an INGREDIENTS
list, populate the "ingredients" array with the main component ingredients in the
order listed — the principal items only (roughly the first 12–15), and do NOT
expand the nested sub-ingredients shown in parentheses. Leave "amount"/"unit" as
empty strings when the label doesn't quantify them. The user records how many
servings they actually ate separately, so always report exactly one serving here.

List every distinct food item or ingredient you can identify in the "ingredients"
array, each with your best estimate of the amount and unit. Be specific — name
the cooking method, variety, or brand if visible (e.g. "grilled chicken breast"
not "meat"). Use sensible units: grams (g), ounces (oz), cups, tablespoons (tbsp),
teaspoons (tsp), millilitres (ml), or pieces.

When you are uncertain about something that significantly affects the nutritional
estimate (e.g. portion size, hidden ingredients, cooking method, sauce type),
include specific, concise questions in the "questions" array. Only ask questions
that would materially change the calorie or nutrient count. Leave "questions"
empty if you are confident enough.

Return ONLY valid JSON with this exact structure (use null for values you cannot estimate):
{
  "description": "Brief description of what you see",
  "confidence": 0.0-1.0,
  "ingredients": [
    {"name": "grilled chicken breast", "amount": "150", "unit": "g"},
    {"name": "white rice", "amount": "1", "unit": "cup"}
  ],
  "questions": ["question 1 if uncertain", "question 2 if uncertain"],
  "calories": number,
  "protein": number,
  "carbohydrates": number,
  "fat": number,
  "saturated_fat": number,
  "fiber": number,
  "sugar": number,
  "vitamin_a": number or null,
  "vitamin_b1": number or null,
  "vitamin_b2": number or null,
  "vitamin_b3": number or null,
  "vitamin_b6": number or null,
  "vitamin_b9": number or null,
  "vitamin_b12": number or null,
  "vitamin_c": number or null,
  "vitamin_d": number or null,
  "vitamin_e": number or null,
  "vitamin_k": number or null,
  "calcium": number or null,
  "iron": number or null,
  "magnesium": number or null,
  "phosphorus": number or null,
  "potassium": number or null,
  "sodium": number or null,
  "zinc": number or null,
  "selenium": number or null
}

Units: calories in kcal; protein/carbohydrates/fat/saturated_fat/fiber/sugar in grams;
vitamin_a in mcg RAE; vitamins B1/B2/B3/B6/C/E in mg; vitamins B9/B12/D/K in mcg;
all minerals in mg except selenium (mcg).
PROMPT;

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly AnalysisAllowance $allowance,
        private readonly LocalePromptBuilder $localePrompt,
    ) {
    }

    /**
     * Analyzes a food image, optionally with prior Q&A context from clarification rounds.
     *
     * Accepts a single path or a list of paths for the SAME meal — e.g. the dish
     * plus a separate nutrition-facts label photo the user adds while refining.
     * All images are sent in one request for a single combined estimate.
     *
     * Metered against the monthly allowance (throws
     * {@see PhotoLimitExceededException} when a Free user has none left) before
     * any Claude call. Pass $reanalysisOf when this re-estimates an already
     * logged meal: that leaves no new row to count, so the call is booked on
     * the ledger against that meal.
     *
     * @param string|array<string>                            $imagePath    one path, or several for one meal
     * @param array<array{question: string, answer: string}> $context
     * @param string|null                                     $notes        the user's free-text meal notes
     * @param MealEntry|null                                  $reanalysisOf the logged meal being re-estimated
     */
    public function analyzeImage(
        User $user,
        string|array $imagePath,
        array $context = [],
        ?string $notes = null,
        ?MealEntry $reanalysisOf = null,
    ): AnalysisResult {
        return $this->allowance->meter(
            $user,
            $reanalysisOf !== null ? AnalysisKind::MealReanalysisPhoto : null,
            $reanalysisOf,
            function () use ($user, $imagePath, $context, $notes): AnalysisResult {
                $paths = array_values(array_filter(
                    is_array($imagePath) ? $imagePath : [$imagePath],
                    static fn(string $p): bool => $p !== '',
                ));
                if ($paths === []) {
                    throw new \RuntimeException('No image to analyze.');
                }

                $prompt = $this->buildPrompt($user, $context, $notes, count($paths));

                return $this->toResult($this->claude->completeWithImages($prompt, $paths, self::MAX_TOKENS));
            },
        );
    }

    /**
     * Photo-free sibling of {@see analyzeImage}: estimates the meal from the
     * user's own description ("chicken caesar salad from Sweetgreen, large, no
     * croutons"). Same JSON contract, same review flow, same Free-tier gate —
     * just a cheaper text-only Claude call. $context carries clarification
     * rounds exactly as in the photo flow, and $reanalysisOf books a
     * re-analysis on the ledger as in {@see analyzeImage()}.
     *
     * @param array<array{question: string, answer: string}> $context
     */
    public function analyzeDescription(
        User $user,
        string $description,
        array $context = [],
        ?string $notes = null,
        ?MealEntry $reanalysisOf = null,
    ): AnalysisResult {
        return $this->allowance->meter(
            $user,
            $reanalysisOf !== null ? AnalysisKind::MealReanalysisDescription : null,
            $reanalysisOf,
            function () use ($user, $description, $context, $notes): AnalysisResult {
                $description = trim($description);
                if ($description === '') {
                    throw new \RuntimeException('No description to analyze.');
                }

                $prompt = "No photo is available for this meal. The user described it as:\n"
                    . $description . "\n\n"
                    . "Estimate from this description instead — where the instructions below "
                    . "refer to the image, apply them to the described meal.\n\n"
                    . $this->buildPrompt($user, $context, $notes, 1);

                return $this->toResult($this->claude->completeWithImages($prompt, [], self::MAX_TOKENS));
            },
        );
    }

    /**
     * Assembles the full analysis prompt: base JSON contract + locale rule +
     * multi-photo note + user notes + clarification context. Shared by the
     * photo and description flows so their behavior can't drift.
     *
     * @param array<array{question: string, answer: string}> $context
     */
    private function buildPrompt(User $user, array $context, ?string $notes, int $imageCount): string
    {
        $prompt = self::BASE_PROMPT;

        // i18n Phase 3: description/questions in the user's language; ingredient
        // names stay English (inflammation keyword matching depends on them).
        $localeRule = $this->localePrompt->mealAnalysisJson($user->getLocale());
        if ($localeRule !== '') {
            $prompt .= "\n\n" . $localeRule;
        }

        if ($imageCount > 1) {
            $prompt = "Multiple photos of the SAME meal are provided (for example the "
                . "prepared dish plus a nutrition-facts label or its packaging). Use ALL "
                . "of them together for one combined estimate.\n\n" . $prompt;
        }

        // The user's free-text notes from the log form (dish name, restaurant,
        // ingredients, preparation…). They know what they ate better than the
        // photo shows, so their statements win over visual inference. Capped
        // defensively — the form's Length constraint is the user-facing bound.
        $notes = mb_substr(trim((string) $notes), 0, self::NOTES_MAX_LENGTH);
        if ($notes !== '') {
            $prompt = "The user added these notes about the meal. Use them to inform your "
                . "analysis (they may name the dish, restaurant, ingredients, preparation, "
                . "or serving size) and treat them as authoritative over visual guesses "
                . "where they conflict:\n" . $notes . "\n\n" . $prompt;
        }

        if (!empty($context)) {
            $parts = [];

            $qa = array_filter($context, fn($p) => isset($p['question']));
            if (!empty($qa)) {
                $parts[] = "Clarifying answers provided:\n" . implode("\n", array_map(
                    fn($pair) => 'Q: ' . $pair['question'] . "\nA: " . $pair['answer'],
                    $qa
                ));
            }

            $corrections = array_filter($context, fn($p) => isset($p['corrected_ingredients']));
            if (!empty($corrections)) {
                $last = end($corrections);
                $list = implode(', ', array_map(
                    fn($i) => $i['amount'] . ' ' . $i['unit'] . ' ' . $i['name'],
                    $last['corrected_ingredients']
                ));
                $parts[] = "The user corrected the ingredient list to: " . $list . ".\n"
                    . "Use this corrected list as the basis for your nutritional calculations.";
            }

            $prompt = implode("\n\n", $parts)
                . "\n\nUsing this additional context, re-analyze the image and update your estimates accordingly.\n\n"
                . $prompt;
        }

        return $prompt;
    }

    private function toResult(ClaudeResult $reply): AnalysisResult
    {
        $data = $reply->json();

        return new AnalysisResult(
            NutritionData::fromClaudeJson($data),
            (float) ($data['confidence'] ?? 0.5),
            (string) ($data['description'] ?? ''),
            array_values(array_filter((array) ($data['questions'] ?? []), 'is_string')),
            IngredientList::fromClaudeJson($data['ingredients'] ?? null),
            $reply->inputTokens,
            $reply->outputTokens,
        );
    }
}
