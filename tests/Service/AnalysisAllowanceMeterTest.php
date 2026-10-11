<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\TokenUsage;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Enum\AnalysisKind;
use App\Exception\PhotoLimitExceededException;
use App\Service\AnalysisAllowance;
use App\Service\ClaudeClient;
use App\Service\FoodAnalysisService;
use App\Service\LocalePromptBuilder;
use App\Service\RecipeAnalysisService;
use App\Service\SupplementAnalysisService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Check-then-book for every analysis: AnalysisAllowance::meter() itself, and
 * which kind (if any) each analysis entry point books. The policy of WHICH
 * kinds are ledgered is docs/adr/0001-mixed-allowance-ledger.md.
 */
final class AnalysisAllowanceMeterTest extends TestCase
{
    use BuildsAllowance;

    private string $imagePath;

    protected function setUp(): void
    {
        $this->imagePath = tempnam(sys_get_temp_dir(), 'meter') . '.jpg';
        file_put_contents($this->imagePath, 'fake-jpeg-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->imagePath);
    }

    public function testAnExhaustedFreeUserIsRefusedBeforeTheAnalysisRuns(): void
    {
        $ran = false;

        try {
            $this->allowance(unlimited: false, used: AnalysisAllowance::FREE_MONTHLY_LIMIT)->meter(
                new User(),
                AnalysisKind::SupplementScan,
                null,
                function () use (&$ran): TokenUsage {
                    $ran = true;

                    return $this->usage(1);
                },
            );
            self::fail('Expected the allowance to refuse.');
        } catch (PhotoLimitExceededException) {
        }

        self::assertFalse($ran);
        self::assertSame([], $this->booked);
    }

    public function testAFreeUserWithAnalysesLeftIsLetThrough(): void
    {
        $result = $this->allowance(unlimited: false, used: AnalysisAllowance::FREE_MONTHLY_LIMIT - 1)
            ->meter(new User(), null, null, fn(): TokenUsage => $this->usage(5));

        self::assertSame(5, $result->totalTokens());
    }

    public function testALedgeredAnalysisIsBookedWithItsTokensAndMeal(): void
    {
        $meal = new MealEntry();

        $this->allowance()->meter(
            new User(),
            AnalysisKind::MealReanalysisPhoto,
            $meal,
            fn(): TokenUsage => $this->usage(1500),
        );

        self::assertCount(1, $this->booked);
        self::assertSame(AnalysisKind::MealReanalysisPhoto, $this->booked[0]->getKind());
        self::assertSame(1500, $this->booked[0]->getTokens());
        self::assertSame($meal, $this->booked[0]->getMealEntry());
    }

    public function testAnUnledgeredAnalysisBooksNothing(): void
    {
        $this->allowance()->meter(new User(), null, null, fn(): TokenUsage => $this->usage(1500));

        self::assertSame([], $this->booked);
    }

    public function testAFailedAnalysisIsNotCharged(): void
    {
        try {
            $this->allowance()->meter(new User(), AnalysisKind::SupplementScan, null, function (): TokenUsage {
                throw new \RuntimeException('upstream 500');
            });
            self::fail('The analysis failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('upstream 500', $e->getMessage());
        }

        self::assertSame([], $this->booked);
    }

    /**
     * @return iterable<string, array{0: \Closure(self): void, 1: ?AnalysisKind}>
     */
    public static function entryPoints(): iterable
    {
        yield 'meal photo' => [fn(self $t) => $t->food()->analyzeImage(new User(), $t->imagePath), null];
        yield 'described meal' => [fn(self $t) => $t->food()->analyzeDescription(new User(), 'soup'), null];
        yield 'meal photo re-analysis' => [
            fn(self $t) => $t->food()->analyzeImage(new User(), $t->imagePath, reanalysisOf: new MealEntry()),
            AnalysisKind::MealReanalysisPhoto,
        ];
        yield 'meal description re-analysis' => [
            fn(self $t) => $t->food()->analyzeDescription(new User(), 'soup', reanalysisOf: new MealEntry()),
            AnalysisKind::MealReanalysisDescription,
        ];
        yield 'recipe import' => [fn(self $t) => $t->recipe()->analyzeImages(new User(), [$t->imagePath]), null];
        yield 'supplement scan' => [
            fn(self $t) => $t->supplement()->analyzeImages(new User(), [$t->imagePath]),
            AnalysisKind::SupplementScan,
        ];
    }

    /**
     * @param \Closure(self): void $analyze
     */
    #[DataProvider('entryPoints')]
    public function testEachAnalysisBooksItsKindOrNothing(\Closure $analyze, ?AnalysisKind $kind): void
    {
        $analyze($this);

        if ($kind === null) {
            self::assertSame([], $this->booked);

            return;
        }
        self::assertCount(1, $this->booked);
        self::assertSame($kind, $this->booked[0]->getKind());
        self::assertSame(1500, $this->booked[0]->getTokens());
    }

    private function food(): FoodAnalysisService
    {
        return new FoodAnalysisService($this->claude(), $this->allowance(), $this->locale());
    }

    private function recipe(): RecipeAnalysisService
    {
        return new RecipeAnalysisService($this->claude(), $this->allowance(), $this->locale());
    }

    private function supplement(): SupplementAnalysisService
    {
        return new SupplementAnalysisService($this->claude(), $this->allowance());
    }

    private function claude(): ClaudeClient
    {
        return new ClaudeClient(new MockHttpClient(new MockResponse((string) json_encode([
            'content' => [['type' => 'text', 'text' => '{"description": "ok", "title": "ok", "calories": 100}']],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300],
        ]))), 'test-key');
    }

    private function locale(): LocalePromptBuilder
    {
        return new LocalePromptBuilder(new LocaleSwitcher('en', []));
    }

    private function usage(int $tokens): TokenUsage
    {
        return new class ($tokens) implements TokenUsage {
            public function __construct(private readonly int $tokens)
            {
            }

            public function totalTokens(): int
            {
                return $this->tokens;
            }
        };
    }
}
