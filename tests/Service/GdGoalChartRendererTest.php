<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\GoalDefinition;
use App\Entity\NutritionGoal;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Service\Chart\GdGoalChartRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GdGoalChartRendererTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension not available.');
        }
        $this->dir = sys_get_temp_dir() . '/nouriloop-charts-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.png') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function goal(GoalDirection $direction, ?float $max = null): NutritionGoal
    {
        $def = new GoalDefinition(
            GoalMetric::Fiber,
            $direction,
            GoalAggregation::DailyAverage,
            28.0,
            7,
            targetMax: $max,
        );

        return (new NutritionGoal())->setName('More fiber')->setDefinition($def);
    }

    public function testRendersValidPngOfExpectedSize(): void
    {
        $renderer = new GdGoalChartRenderer($this->dir, new NullLogger());
        $daily = ['2026-06-08' => 12.0, '2026-06-09' => 18.0, '2026-06-10' => 22.0];

        $path = $renderer->render($this->goal(GoalDirection::AtLeast), $daily, 'check1');

        self::assertSame('/uploads/goal-charts/check1.png', $path);
        $file = $this->dir . '/check1.png';
        self::assertFileExists($file);

        $info = getimagesize($file);
        self::assertIsArray($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
        self::assertSame(620, $info[0]);
        self::assertSame(300, $info[1]);
    }

    public function testMaintainBandAndSinglePointAndEmpty(): void
    {
        $renderer = new GdGoalChartRenderer($this->dir, new NullLogger());

        // Maintain range with a single day (exercises the band + n==1 path).
        $path = $renderer->render($this->goal(GoalDirection::Maintain, 35.0), ['2026-06-10' => 30.0], 'check2');
        self::assertFileExists($this->dir . '/check2.png');
        self::assertNotNull($path);

        // No data → no chart.
        self::assertNull($renderer->render($this->goal(GoalDirection::AtLeast), [], 'check3'));
    }
}
