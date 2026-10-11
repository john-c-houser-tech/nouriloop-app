<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Intake\DailyIntake;
use App\Intake\IntakeAverage;
use App\Service\SupplementRecommendationService;
use App\Service\UserTime;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SupplementRecommendationServiceTest extends TestCase
{
    public function testANutrientUnderThreeQuartersOfItsRdaIsAGapWorstFirst(): void
    {
        $gaps = $this->service()->computeGaps($this->intake(['fiber' => 14.0, 'vitaminC' => 80.0]));
        $byNutrient = array_column($gaps, 'pct', 'nutrient');

        self::assertSame(50.0, $byNutrient['fiber']);         // 14 of 28 g
        self::assertArrayNotHasKey('vitaminC', $byNutrient);  // 80 of 90 mg is 89 %

        $pcts = array_column($gaps, 'pct');
        $sorted = $pcts;
        sort($sorted);
        self::assertSame($sorted, $pcts, 'furthest short first');
    }

    public function testAnEmptyOrAllZeroAverageIsNoDataNotEveryNutrientMissing(): void
    {
        self::assertSame([], $this->service()->computeGaps([]));
        self::assertSame([], $this->service()->computeGaps($this->intake([])));
        self::assertFalse($this->service()->hasNutritionData([]));
    }

    public function testSupplementsAloneProduceNoGapsWithoutAMealDay(): void
    {
        // No meal days in the window means no intake, supplements included (ADR 0002);
        // until 2026-10-06 a supplement on its own produced gaps.
        $intake = $this->createStub(DailyIntake::class);
        $intake->method('average')->willReturn(null);

        self::assertSame([], $this->service($intake)->getRecommendations($this->user()));
    }

    public function testRecommendationsReadTheIntakeAverageWithSupplementsIncluded(): void
    {
        $intake = $this->createStub(DailyIntake::class);
        $intake->method('average')->willReturn(new IntakeAverage(
            $this->intake(['fiber' => 14.0]),
            ['fiber' => 10.0],
            3,
        ));
        $gaps = $this->service($intake)->getRecommendations($this->user());

        self::assertNotContains('fiber', array_column($gaps, 'nutrient'), '24 of 28 g is no longer a gap');
    }

    /**
     * @param array<string, float> $nonZero
     * @return array<string, float>
     */
    private function intake(array $nonZero): array
    {
        $keys = ['calories', 'protein', 'fiber', 'vitaminA', 'vitaminC', 'vitaminD', 'iron'];

        return $nonZero + array_fill_keys($keys, 0.0);
    }

    private function service(?DailyIntake $intake = null): SupplementRecommendationService
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new SupplementRecommendationService(
            $intake ?? $this->createStub(DailyIntake::class),
            new UserTime(),
            $translator,
        );
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getTimezone')->willReturn('America/Los_Angeles');

        return $user;
    }
}
