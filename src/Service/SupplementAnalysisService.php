<?php

declare(strict_types=1);

namespace App\Service;

use App\Ai\AiClient;
use App\Ai\AiFeature;
use App\Dto\SupplementAnalysis;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\AnalysisKind;
use App\Exception\PhotoLimitExceededException;

class SupplementAnalysisService
{
    private const MAX_TOKENS = 1024;

    private const PROMPT = <<<'PROMPT'
These photos show ONE dietary supplement's label — possibly different parts of the
same wrapped-around label. Combine information across all of them. Read the
"Supplement Facts" panel and return the amount of each nutrient in ONE serving.

Rules:
- Report the absolute amount per serving, not the % Daily Value. If only a %DV is
  shown, convert it to the absolute amount using standard US Daily Values.
- Convert IU to mass where applicable (vitamin A, D, E).
- Use null for any nutrient not listed on the label.
- Also return the product name and the serving size text (e.g. "2 capsules").

Also read the "Nutrition Facts" macros if the label lists them (calories, protein,
total carbohydrate, total fat, saturated fat, total sugars, sodium, dietary fiber).

Units: calories in kcal; protein/carbohydrates/fat/saturated_fat/sugar/fiber in
grams (g); sodium in mg; vitamin_a in mcg RAE; vitamins B1/B2/B3/B6/C/E in mg;
vitamins B9/B12/D/K in mcg; all other minerals in mg except selenium (mcg).

Return ONLY valid JSON with this exact structure:
{
  "name": "product name",
  "serving_size": "e.g. 2 capsules",
  "confidence": 0.0-1.0,
  "calories": number or null,
  "protein": number or null,
  "carbohydrates": number or null,
  "fat": number or null,
  "saturated_fat": number or null,
  "sugar": number or null,
  "sodium": number or null,
  "fiber": number or null,
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
  "potassium": number or null,
  "zinc": number or null,
  "selenium": number or null
}
PROMPT;

    public function __construct(
        private readonly AiClient $ai,
        private readonly AnalysisAllowance $allowance,
    ) {
    }

    /**
     * Draws on the shared Free-tier AnalysisAllowance like the meal and recipe
     * analyzers. A scan leaves no countable row until (and unless) the user
     * saves it, so it is booked on the call-time ledger once it succeeds — the
     * meal re-analysis posture; an abandoned scan still counts.
     *
     * @param list<string> $imagePaths one or more photos of the same supplement label
     *
     * @throws PhotoLimitExceededException when a Free user's allowance is exhausted
     */
    public function analyzeImages(User $user, array $imagePaths): SupplementAnalysis
    {
        return $this->allowance->meter(
            $user,
            AnalysisKind::SupplementScan,
            null,
            function () use ($user, $imagePaths): SupplementAnalysis {
                $reply = $this->ai->completeWithImages(
                    AiFeature::SupplementScan,
                    $user,
                    self::PROMPT,
                    $imagePaths,
                    self::MAX_TOKENS,
                );
                $data = $reply->json();

                return new SupplementAnalysis(
                    name: trim((string) ($data['name'] ?? 'Supplement')) ?: 'Supplement',
                    servingSize: trim((string) ($data['serving_size'] ?? '')),
                    confidence: (float) ($data['confidence'] ?? 0.5),
                    nutrients: NutritionData::nutrientsFromClaudeJson($data),
                    inputTokens: $reply->inputTokens,
                    outputTokens: $reply->outputTokens,
                );
            },
        );
    }
}
