<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\RecipeAnalysis;
use App\Entity\NutritionData;
use App\Entity\User;

/**
 * Reads a photographed recipe — cookbook page, printed web page, or a
 * handwritten card (front/back or several pages) — in ONE Claude vision call
 * that does OCR and batch-nutrition estimation together, the same way
 * FoodAnalysisService does ingredient ID + nutrition in one pass. The result
 * feeds the recipe Confirm screen and is saved as a normal Favorite divided
 * down to one serving; no separate recipe entity exists.
 *
 * Gated by the same Free-tier monthly allowance as meal analyses
 * (AnalysisAllowance — one shared bucket).
 */
class RecipeAnalysisService
{
    /**
     * Recipes carry long ingredient lists plus a transcription of the steps,
     * so the cap is double the meal analysis's 2048.
     */
    private const MAX_TOKENS = 4096;

    private const BASE_PROMPT = <<<'PROMPT'
These photos show ONE recipe — for example a cookbook page, a printed web page,
or a handwritten recipe card photographed front and back or across several
pages. Combine information from ALL the photos: transcribe the recipe, then
estimate its nutrition.

Rules:
- "title": the recipe's name as written; if none is written, a short
  descriptive name for the dish.
- "servings": the stated yield as a single number ("serves 4" -> 4, "makes 12
  muffins" -> 12, "serves 4-6" -> 5). If no yield is stated, give your best
  estimate AND add a clarifying question asking how many servings it makes.
- "ingredients": every ingredient line, each with name, amount, and unit. Be
  specific in names (variety, cut, preparation — "boneless chicken thighs",
  not "meat"). Keep vague quantities exactly as written ("a pinch", "to
  taste") in "amount" with an empty "unit". Use sensible units: grams (g),
  ounces (oz), cups, tablespoons (tbsp), teaspoons (tsp), millilitres (ml),
  or pieces.
- "instructions": a faithful plain-text transcription of the preparation
  steps, condensed only where the writing is illegible; "" if no steps are
  visible.
- Nutrition values are totals for the ENTIRE recipe as written — all servings
  combined, NOT per serving. Estimate from the full ingredient amounts.
- "confidence" 0.0-1.0 for the overall read (lower it when handwriting is hard
  to read, amounts are unclear, or the yield is a guess).
- "questions": specific, concise questions where something illegible or
  ambiguous would materially change the nutrition estimate. Leave empty if you
  are confident enough.

Return ONLY valid JSON with this exact structure (use null for values you cannot estimate):
{
  "title": "recipe name",
  "servings": number or null,
  "confidence": 0.0-1.0,
  "ingredients": [
    {"name": "boneless chicken thighs", "amount": "1", "unit": "lb"},
    {"name": "olive oil", "amount": "2", "unit": "tbsp"}
  ],
  "instructions": "the preparation steps as written",
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
     * Reads all photos of one recipe in a single request. $context carries
     * clarification rounds from the Confirm screen (answered questions and/or
     * a corrected ingredient list), exactly like the meal flow's re-analysis.
     *
     * @param string[]                                        $imagePaths
     * @param array<array<string, mixed>>                     $context
     */
    public function analyzeImages(User $user, array $imagePaths, array $context = []): RecipeAnalysis
    {
        // Not ledgered: the saved favorite is what the derived count sees.
        $analysis = function () use ($user, $imagePaths, $context): RecipeAnalysis {
            $paths = array_values(array_filter($imagePaths, static fn(string $p): bool => $p !== ''));
            if ($paths === []) {
                throw new \RuntimeException('No recipe images to analyze.');
            }

            $prompt = $this->buildPrompt($user, $context);
            $reply = $this->claude->completeWithImages($prompt, $paths, self::MAX_TOKENS);
            $data = $reply->json();

            $servings = $data['servings'] ?? null;

            // No hardcoded English fallback title: '' is data the confirm screen's
            // required field makes the user fill in (their language, their words).
            return new RecipeAnalysis(
                title: trim((string) ($data['title'] ?? '')),
                servings: is_numeric($servings) && (float) $servings > 0 ? (float) $servings : null,
                nutrition: NutritionData::fromClaudeJson($data),
                confidence: (float) ($data['confidence'] ?? 0.5),
                questions: array_values(array_filter((array) ($data['questions'] ?? []), 'is_string')),
                ingredients: IngredientList::fromClaudeJson($data['ingredients'] ?? null),
                instructions: trim((string) ($data['instructions'] ?? '')),
                inputTokens: $reply->inputTokens,
                outputTokens: $reply->outputTokens,
            );
        };

        return $this->allowance->meter($user, null, null, $analysis);
    }

    /**
     * Base contract + locale rule + clarification context, mirroring
     * FoodAnalysisService::buildPrompt's context block shapes.
     *
     * @param array<array<string, mixed>> $context
     */
    private function buildPrompt(User $user, array $context): string
    {
        $prompt = self::BASE_PROMPT;

        $localeRule = $this->localePrompt->recipeAnalysisJson($user->getLocale());
        if ($localeRule !== '') {
            $prompt .= "\n\n" . $localeRule;
        }

        if ($context !== []) {
            $parts = [];

            $qa = array_filter($context, static fn(array $p): bool => isset($p['question']));
            if ($qa !== []) {
                $parts[] = "Clarifying answers provided:\n" . implode("\n", array_map(
                    static fn(array $pair): string => 'Q: ' . $pair['question'] . "\nA: " . $pair['answer'],
                    $qa,
                ));
            }

            $corrections = array_filter($context, static fn(array $p): bool => isset($p['corrected_ingredients']));
            if ($corrections !== []) {
                $last = end($corrections);
                $list = implode(', ', array_map(
                    static fn(array $i): string => trim($i['amount'] . ' ' . $i['unit'] . ' ' . $i['name']),
                    $last['corrected_ingredients'],
                ));
                $parts[] = 'The user corrected the ingredient list to: ' . $list . ".\n"
                    . 'Use this corrected list as the basis for your nutritional calculations.';
            }

            if ($parts !== []) {
                $prompt = implode("\n\n", $parts)
                    . "\n\nUsing this additional context, re-read the recipe and update your estimates accordingly.\n\n"
                    . $prompt;
            }
        }

        return $prompt;
    }
}
