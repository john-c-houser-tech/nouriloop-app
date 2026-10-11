<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\GoalDefinition;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalMetric;
use App\Service\GoalSafetyValidator;
use PHPUnit\Framework\TestCase;

final class GoalSafetyValidatorTest extends TestCase
{
    private GoalSafetyValidator $validator;

    protected function setUp(): void
    {
        $translator = new \Symfony\Component\Translation\Translator('en');
        $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());
        $translator->addResource(
            'yaml',
            __DIR__ . '/../../translations/messages+intl-icu.en.yaml',
            'en',
            'messages+intl-icu',
        );
        $this->validator = new GoalSafetyValidator($translator);
    }

    private function def(
        GoalMetric $metric,
        GoalDirection $direction,
        float $target,
        int $window = 7,
    ): GoalDefinition {
        return new GoalDefinition($metric, $direction, GoalAggregation::DailyAverage, $target, $window);
    }

    public function testReasonableGoalHasNoWarnings(): void
    {
        $def = $this->def(GoalMetric::Sugar, GoalDirection::AtMost, 30.0);

        self::assertSame([], $this->validator->validate($def));
    }

    public function testFlagsLowCalorieFloor(): void
    {
        $def = $this->def(GoalMetric::Calories, GoalDirection::AtMost, 900.0);

        $warnings = $this->validator->validate($def);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('1,200', $warnings[0]);
    }

    public function testNormalCalorieCeilingIsFine(): void
    {
        $def = $this->def(GoalMetric::Calories, GoalDirection::AtMost, 2000.0);

        self::assertSame([], $this->validator->validate($def));
    }

    public function testFlagsAtLeastGoalForThingsToLimit(): void
    {
        $def = $this->def(GoalMetric::Sugar, GoalDirection::AtLeast, 100.0);

        $warnings = $this->validator->validate($def);

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('unusual', $warnings[0]);
    }

    public function testFlagsNonPositiveTargetAndBadWindow(): void
    {
        $def = $this->def(GoalMetric::Fiber, GoalDirection::AtLeast, 0.0, 365);

        $warnings = $this->validator->validate($def);

        self::assertCount(2, $warnings);
    }
}
