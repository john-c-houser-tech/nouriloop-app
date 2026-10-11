<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Test double for the Anthropic Messages API.
 *
 * Wired (in config/services_test.yaml) as the response factory of a
 * MockHttpClient that replaces the real HttpClient injected into
 * FoodAnalysisService, SupplementAnalysisService and ClaudeClient. So every
 * Claude call in the E2E suite returns deterministic, canned JSON — no network,
 * no tokens, no flakiness. The shapes here mirror exactly what each service's
 * parser expects (see those classes' BASE_PROMPT / PROMPT / parseProposal).
 */
final class FakeAnthropicResponder
{
    /**
     * Failure triggers for tests: meal notes flow into the analysis prompt, so a
     * test can put one of these markers in the notes field to make "Claude" fail
     * — ALWAYS on every call, ONCE only on the first call after a reset (so the
     * retry flow's failure→success path is exercisable end-to-end).
     */
    public const FAIL_ALWAYS = 'TRIGGER-ANALYSIS-FAILURE-ALWAYS';
    public const FAIL_ONCE = 'TRIGGER-ANALYSIS-FAILURE-ONCE';

    /**
     * Returns a meal estimate with confidence exactly 0.0 — a valid value under
     * the analysis contract that truthiness checks silently swallow (it hid the
     * re-analysis review's confidence line until 2026-07-29).
     */
    public const ZERO_CONFIDENCE = 'TRIGGER-ZERO-CONFIDENCE';

    private static bool $failedOnce = false;

    /** The prompt of the most recent analysis (vision-shaped) call, for tests that check what was sent. */
    private static ?string $lastAnalysisPrompt = null;

    public static function resetFailureTrigger(): void
    {
        self::$failedOnce = false;
    }

    /**
     * The prompt text the most recent analysis call sent (static, so it survives
     * the kernel reboot between a test's requests). Null until one is made.
     */
    public static function lastAnalysisPrompt(): ?string
    {
        return self::$lastAnalysisPrompt;
    }

    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $payload = $this->payload($options);
        $messages = $payload['messages'] ?? [];
        $system = (string) ($payload['system'] ?? '');

        // Text conversation (ClaudeClient): goal setup or goal evaluation.
        if (\is_string($messages[0]['content'] ?? null) || $system !== '') {
            return $this->envelope($this->textReply($system, $messages));
        }

        // Vision call (FoodAnalysisService / SupplementAnalysisService): the user
        // message content is a list of blocks; the trailing text block is the prompt.
        $prompt = $this->lastTextBlock($messages);
        self::$lastAnalysisPrompt = $prompt;

        if (str_contains($prompt, self::FAIL_ALWAYS)) {
            return $this->upstreamError();
        }
        if (str_contains($prompt, self::FAIL_ONCE) && !self::$failedOnce) {
            self::$failedOnce = true;

            return $this->upstreamError();
        }

        if (str_contains($prompt, self::ZERO_CONFIDENCE)) {
            return $this->envelope((string) json_encode(
                ['confidence' => 0.0] + json_decode($this->mealJson(), true),
            ));
        }

        if (str_contains($prompt, 'dietary supplement')) {
            return $this->envelope($this->supplementJson());
        }

        // The recipe-import prompt opens with "These photos show ONE recipe".
        if (str_contains($prompt, 'ONE recipe')) {
            return $this->envelope($this->recipeJson());
        }

        return $this->envelope($this->mealJson());
    }

    /** Decode the request payload whether HttpClient kept it under 'json' or serialized 'body'. */
    private function payload(array $options): array
    {
        if (isset($options['json']) && \is_array($options['json'])) {
            return $options['json'];
        }
        if (isset($options['body']) && \is_string($options['body'])) {
            $decoded = json_decode($options['body'], true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function lastTextBlock(array $messages): string
    {
        $content = $messages[0]['content'] ?? [];
        if (!\is_array($content)) {
            return '';
        }
        foreach (array_reverse($content) as $block) {
            if (($block['type'] ?? null) === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        return '';
    }

    /** @param array<int, array{role: string, content: string}> $messages */
    private function textReply(string $system, array $messages): string
    {
        // The natural-language help assistant: return the answer-JSON shape it parses.
        if (str_contains($system, 'NouriLoop help assistant')) {
            return (string) json_encode([
                'answer' => 'To log a meal, tap **Log**, take a photo, and confirm the estimate before saving.',
                'pages' => [
                    ['title' => 'Logging a Meal', 'url' => '/help/log-meal', 'reason' => 'Step-by-step photo logging.'],
                ],
            ]);
        }

        // Predictive Help: the "what if" projection narrator and the free-text
        // estimator both ask for plain prose grounded in computed numbers.
        if (str_contains($system, 'what if') || str_contains($system, 'hypothetical dietary change')) {
            return 'Eating this daily would modestly raise your protein and fiber while nudging '
                . 'your inflammation score lower. This is an educational estimate, not medical advice.';
        }

        // The goal-setup coach asks for a fenced proposal once the goal is settled.
        if (str_contains($system, 'goal-proposal') || str_contains($system, 'nutrition coach')) {
            $proposal = json_encode([
                'name' => 'More fiber',
                'metric' => 'fiber',
                'direction' => 'at_least',
                'aggregation' => 'daily_average',
                'targetValue' => 30,
                'targetMax' => null,
                'evaluationWindowDays' => 7,
                'frequency' => 'weekly',
                'dayOfWeek' => 1,
                'hour' => 8,
                'minute' => 0,
                'outputFormat' => 'summary',
                'deliveryChannel' => 'email',
                'rationale' => 'A modest step up from your current average.',
            ], JSON_PRETTY_PRINT);

            return "Great — here's a goal that fits.\n\n```goal-proposal\n{$proposal}\n```";
        }

        // Otherwise it's a goal-evaluation narrative (or any other text turn).
        return 'You are tracking well against your goal this week — keep it up.';
    }

    private function mealJson(): string
    {
        return (string) json_encode([
            'description' => 'Grilled chicken breast with white rice and broccoli',
            'confidence' => 0.86,
            'ingredients' => [
                ['name' => 'grilled chicken breast', 'amount' => '150', 'unit' => 'g'],
                ['name' => 'white rice', 'amount' => '1', 'unit' => 'cup'],
                ['name' => 'broccoli', 'amount' => '80', 'unit' => 'g'],
            ],
            'questions' => [],
            'calories' => 520,
            'protein' => 46,
            'carbohydrates' => 58,
            'fat' => 9,
            'saturated_fat' => 2,
            'fiber' => 4,
            'sugar' => 2,
            'vitamin_a' => 90,
            'vitamin_c' => 60,
            'vitamin_d' => 0.2,
            'vitamin_e' => 1.5,
            'vitamin_b6' => 1.1,
            'vitamin_b9' => 70,
            'vitamin_b12' => 0.6,
            'calcium' => 55,
            'iron' => 2.1,
            'magnesium' => 80,
            'phosphorus' => 320,
            'potassium' => 720,
            'sodium' => 410,
            'zinc' => 2.4,
            'selenium' => 30,
        ]);
    }

    private function supplementJson(): string
    {
        return (string) json_encode([
            'name' => 'Test Multivitamin',
            'serving_size' => '1 tablet',
            'confidence' => 0.92,
            'vitamin_c' => 90,
            'vitamin_d' => 25,
            'vitamin_b6' => 2,
            'vitamin_b12' => 6,
            'vitamin_e' => 15,
            'zinc' => 11,
            'magnesium' => 100,
            'iron' => 8,
            'calcium' => 200,
            'selenium' => 55,
        ]);
    }

    /**
     * A whole-batch recipe read (RecipeAnalysisService): 8 servings with
     * round numbers, so tests can assert the per-serving division exactly
     * (1600 kcal / 8 = 200), plus one open question to exercise the
     * clarify/re-analyze path.
     */
    private function recipeJson(): string
    {
        return (string) json_encode([
            'title' => "Grandma's Lentil Soup",
            'servings' => 8,
            'confidence' => 0.78,
            'ingredients' => [
                ['name' => 'dried green lentils', 'amount' => '2', 'unit' => 'cups'],
                ['name' => 'olive oil', 'amount' => '2', 'unit' => 'tbsp'],
                ['name' => 'salt', 'amount' => 'to taste', 'unit' => ''],
            ],
            'instructions' => 'Saute the onion in oil. Add lentils and stock; simmer 40 minutes.',
            'questions' => ['Is the stock in step 2 low-sodium or regular?'],
            'calories' => 1600,
            'protein' => 96,
            'carbohydrates' => 240,
            'fat' => 24,
            'saturated_fat' => 4,
            'fiber' => 64,
            'sugar' => 16,
            'iron' => 26,
            'potassium' => 3800,
            'sodium' => 2400,
        ]);
    }

    private function upstreamError(): MockResponse
    {
        return new MockResponse('upstream unavailable', ['http_code' => 500]);
    }

    private function envelope(string $text): MockResponse
    {
        $body = (string) json_encode([
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300],
        ]);

        return new MockResponse($body, [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
