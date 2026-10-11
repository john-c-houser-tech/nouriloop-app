<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\ClaudeClient;
use App\Service\FoodAnalysisService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FoodAnalysisServiceTest extends TestCase
{
    use BuildsAllowance;

    private string $imagePath;

    protected function setUp(): void
    {
        // analyzeImage() reads + base64-encodes the file, so it must exist.
        $this->imagePath = tempnam(sys_get_temp_dir(), 'meal') . '.jpg';
        file_put_contents($this->imagePath, 'fake-jpeg-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->imagePath);
    }

    public function testCapturesUsageTokensFromResponse(): void
    {
        $result = $this->analyzeWith([
            'content' => [['type' => 'text', 'text' => json_encode([
                'description' => 'A bowl of oatmeal',
                'confidence'  => 0.9,
                'ingredients' => [['name' => 'oats', 'amount' => '80', 'unit' => 'g']],
                'questions'   => [],
                'calories'    => 300,
                'protein'     => 10,
            ])]],
            'usage' => ['input_tokens' => 1234, 'output_tokens' => 567],
        ]);

        self::assertSame(1234, $result->inputTokens);
        self::assertSame(567, $result->outputTokens);
        self::assertSame(1801, $result->totalTokens());
        self::assertSame('A bowl of oatmeal', $result->description);
        self::assertSame(300.0, $result->nutrition->getCalories());
    }

    public function testNonNumericNutrientValuesAreNoEstimateNotZero(): void
    {
        $result = $this->analyzeWith([
            'content' => [['type' => 'text', 'text' => json_encode([
                'description' => 'Soup',
                'confidence'  => 0.8,
                'calories'    => '~20',
                'protein'     => 'unknown',
                'fat'         => '4.5',
                'fiber'       => 3,
            ])]],
        ]);

        self::assertNull($result->nutrition->getCalories());
        self::assertNull($result->nutrition->getProtein());
        self::assertSame(4.5, $result->nutrition->getFat());
        self::assertSame(3.0, $result->nutrition->getFiber());
    }

    public function testMissingUsageDefaultsToZeroTokens(): void
    {
        $result = $this->analyzeWith([
            'content' => [['type' => 'text', 'text' => json_encode([
                'description' => 'Toast',
                'confidence'  => 0.8,
                'calories'    => 150,
            ])]],
            // no "usage" key
        ]);

        self::assertSame(0, $result->inputTokens);
        self::assertSame(0, $result->outputTokens);
        self::assertSame(0, $result->totalTokens());
    }

    public function testParsesJsonWrappedInMarkdownFences(): void
    {
        // Claude sometimes wraps the JSON in ```json … ``` fences, occasionally
        // with leading whitespace — this used to surface as "returned invalid JSON".
        $payload = json_encode([
            'description' => 'A single Andes Crème de Menthe chocolate mint',
            'confidence'  => 0.95,
            'calories'    => 25,
            'sugar'       => 3.5,
        ]);

        $result = $this->analyzeWith([
            'content' => [['type' => 'text', 'text' => "  ```json\n{$payload}\n```"]],
        ]);

        self::assertSame('A single Andes Crème de Menthe chocolate mint', $result->description);
        self::assertSame(25.0, $result->nutrition->getCalories());
    }

    public function testSpanishUserPromptLocalizesProseButPinsEnglishIngredientNames(): void
    {
        $body = $this->captureRequestBody(\App\Enum\SupportedLocale::SpanishMexico);

        // The user-facing fields localize…
        self::assertStringContainsString('The user speaks Mexican Spanish', $body);
        // …but ingredient names must stay English — InflammationScoreService's
        // food-category adjustments are English keyword matches on them.
        self::assertStringContainsString('MUST remain in English', $body);
        self::assertStringContainsString('regional name', $body);
    }

    public function testEnglishUserPromptCarriesNoLocaleInstruction(): void
    {
        $body = $this->captureRequestBody(\App\Enum\SupportedLocale::English);

        self::assertStringNotContainsString('The user speaks', $body);
        self::assertStringNotContainsString('MUST remain in English', $body);
    }

    public function testNotesReachThePromptWhenProvided(): void
    {
        $body = $this->captureRequestBody(
            \App\Enum\SupportedLocale::English,
            notes: "Chipotle chicken burrito, double meat, no rice",
        );

        self::assertStringContainsString('The user added these notes about the meal', $body);
        self::assertStringContainsString('Chipotle chicken burrito, double meat, no rice', $body);
        self::assertStringContainsString('authoritative over visual guesses', $body);
    }

    public function testBlankNotesLeaveThePromptUntouched(): void
    {
        $body = $this->captureRequestBody(\App\Enum\SupportedLocale::English, notes: "   ");

        self::assertStringNotContainsString('The user added these notes', $body);
    }

    public function testDescriptionAnalysisBuildsATextOnlyRequest(): void
    {
        $body = $this->captureRequestBody(
            \App\Enum\SupportedLocale::English,
            describe: 'chicken caesar salad from Sweetgreen, large, no croutons',
        );

        self::assertStringContainsString('No photo is available for this meal', $body);
        self::assertStringContainsString('chicken caesar salad from Sweetgreen', $body);
        // The shared JSON contract rides along unchanged…
        self::assertStringContainsString('questions', $body);
        self::assertStringContainsString('selenium', $body);
        // …and no image block is sent (image blocks always carry base64 data).
        self::assertStringNotContainsString('base64', $body);
    }

    public function testDescriptionAnalysisCarriesNotesToo(): void
    {
        $body = $this->captureRequestBody(
            \App\Enum\SupportedLocale::English,
            notes: 'the dressing was on the side',
            describe: 'big greek salad',
        );

        self::assertStringContainsString('The user added these notes about the meal', $body);
        self::assertStringContainsString('the dressing was on the side', $body);
    }

    public function testBlankDescriptionThrows(): void
    {
        $service = new FoodAnalysisService(
            new ClaudeClient(new MockHttpClient(), 'test-key'),
            $this->allowance(),
            new \App\Service\LocalePromptBuilder(new \Symfony\Component\Translation\LocaleSwitcher('en', [])),
        );

        $this->expectException(\RuntimeException::class);
        $service->analyzeDescription(new User(), '   ');
    }

    private function captureRequestBody(
        \App\Enum\SupportedLocale $locale,
        ?string $notes = null,
        ?string $describe = null,
    ): string {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = (string) $options['body'];

            return new MockResponse(json_encode([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'description' => 'ok',
                    'confidence'  => 0.9,
                    'calories'    => 100,
                ])]],
            ]));
        });

        $service = new FoodAnalysisService(
            new ClaudeClient($client, 'test-key'),
            $this->allowance(),
            new \App\Service\LocalePromptBuilder(new \Symfony\Component\Translation\LocaleSwitcher('en', [])),
        );

        $user = new User();
        $user->setLocale($locale);
        if ($describe !== null) {
            $service->analyzeDescription($user, $describe, [], $notes);
        } else {
            $service->analyzeImage($user, $this->imagePath, [], $notes);
        }

        return (string) $captured;
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    private function analyzeWith(array $responseBody): \App\Dto\AnalysisResult
    {
        $client = new MockHttpClient(new MockResponse(json_encode($responseBody)));

        $service = new FoodAnalysisService(
            new ClaudeClient($client, 'test-key'),
            $this->allowance(),
            new \App\Service\LocalePromptBuilder(new \Symfony\Component\Translation\LocaleSwitcher('en', [])),
        );

        return $service->analyzeImage(new User(), $this->imagePath);
    }
}
