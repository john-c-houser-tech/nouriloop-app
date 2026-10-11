<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\ConfidenceLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfidenceLevelTest extends TestCase
{
    public function testPercentClampsOutOfRangeScores(): void
    {
        self::assertSame(0, (new ConfidenceLevel(-0.2))->percent());
        self::assertSame(100, (new ConfidenceLevel(1.5))->percent());
        self::assertSame(75, (new ConfidenceLevel(0.75))->percent());
    }

    public function testIsConfidentAtThresholdBoundary(): void
    {
        self::assertFalse((new ConfidenceLevel(0.74))->isConfident());
        self::assertTrue((new ConfidenceLevel(ConfidenceLevel::CLARIFY_THRESHOLD))->isConfident());
        self::assertTrue((new ConfidenceLevel(0.9))->isConfident());
    }

    #[DataProvider('bucketProvider')]
    public function testLabelAndColorBuckets(float $score, string $label, string $color): void
    {
        $level = new ConfidenceLevel($score);
        self::assertSame($label, $level->label());
        self::assertSame($color, $level->bootstrapColor());
    }

    /**
     * @return array<string, array{float, string, string}>
     */
    public static function bucketProvider(): array
    {
        return [
            'high boundary'   => [0.85, 'High', 'success'],
            'medium boundary' => [0.60, 'Medium', 'warning'],
            'just below high' => [0.84, 'Medium', 'warning'],
            'low'             => [0.59, 'Low', 'danger'],
            'zero'            => [0.0, 'Low', 'danger'],
        ];
    }
}
