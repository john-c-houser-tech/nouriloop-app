<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Ai\AnthropicClient;
use App\Dto\RecipeAnalysis;
use App\Entity\User;
use App\Enum\SupportedLocale;
use App\Service\LocalePromptBuilder;
use App\Service\RecipeAnalysisService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\LocaleSwitcher;

final class RecipeAnalysisServiceTest extends TestCase
{
    use BuildsAllowance;

    private string $imagePath;

    protected function setUp(): void
    {
        $this->imagePath = tempnam(sys_get_temp_dir(), 'recipe') . '.jpg';
        file_put_contents($this->imagePath, 'fake-jpeg-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->imagePath);
    }

    public function testParsesTheWholeBatchReadIncludingTokens(): void
    {
        $result = $this->analyzeWith([
            'content' => [['type' => 'text', 'text' => json_encode([
                'title'        => 'Beef Stew',
                'servings'     => 6,
                'confidence'   => 0.82,
                'ingredients'  => [['name' => 'beef chuck', 'amount' => '2', 'unit' => 'lb']],
                'instructions' => 'Brown the beef. Simmer 2 hours.',
                'questions'    => [],
                'calories'     => 2400,
                'protein'      => 180,
            ])]],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 400],
        ]);

        self::assertSame('Beef Stew', $result->title);
        self::assertSame(6.0, $result->servings);
        self::assertSame(2400.0, $result->nutrition->getCalories(), 'nutrition is the whole batch');
        self::assertSame('Brown the beef. Simmer 2 hours.', $result->instructions);
        self::assertSame(2400, $result->totalTokens());
    }

    public function testMissingYieldComesBackAsNullServings(): void
    {
        $result = $this->analyzeWith([
            'content' => [['type' => 'text', 'text' => json_encode([
                'title'      => 'Casserole',
                'servings'   => null,
                'confidence' => 0.6,
                'questions'  => ['How many servings does this make?'],
                'calories'   => 1800,
            ])]],
        ]);

        self::assertNull($result->servings);
        self::assertNotEmpty($result->questions);
    }

    public function testPromptCarriesTheContractLocaleRuleAndContext(): void
    {
        $user = new User();
        $user->setLocale(SupportedLocale::Spanish);

        $body = $this->captureRequestBody($user, [
            ['question' => 'How many servings?', 'answer' => 'It makes 8'],
            ['corrected_ingredients' => [['name' => 'brown lentils', 'amount' => '2', 'unit' => 'cups']]],
        ]);

        self::assertStringContainsString('ONE recipe', $body, 'the FakeAnthropicResponder matcher anchor');
        self::assertStringContainsString('ENTIRE recipe', $body);
        // i18n Phase 3: title/questions localize, ingredient names stay English.
        self::assertStringContainsString('MUST remain in English', $body);
        // Refinement context, in the meal flow's shapes.
        self::assertStringContainsString('Q: How many servings?', $body);
        self::assertStringContainsString('A: It makes 8', $body);
        self::assertStringContainsString('2 cups brown lentils', $body);
    }

    public function testInvalidJsonReplyThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->analyzeWith([
            'content' => [['type' => 'text', 'text' => 'I could not read this recipe, sorry!']],
        ]);
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    private function analyzeWith(array $responseBody): RecipeAnalysis
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode($responseBody)));

        return $this->service($client)->analyzeImages(new User(), [$this->imagePath]);
    }

    /**
     * @param array<array<string, mixed>> $context
     */
    private function captureRequestBody(User $user, array $context): string
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = (string) $options['body'];

            return new MockResponse((string) json_encode([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'title' => 'ok', 'servings' => 8, 'confidence' => 0.9, 'calories' => 100,
                ])]],
            ]));
        });

        $this->service($client)->analyzeImages($user, [$this->imagePath], $context);

        return (string) $captured;
    }

    private function service(MockHttpClient $client): RecipeAnalysisService
    {
        return new RecipeAnalysisService(
            new AnthropicClient($client, 'test-key'),
            $this->allowance(),
            new LocalePromptBuilder(new LocaleSwitcher('en', [])),
        );
    }
}
