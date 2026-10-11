<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\MealType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MealTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{int, MealType}>
     */
    public static function hourProvider(): iterable
    {
        yield 'early breakfast'  => [4, MealType::Breakfast];
        yield 'late breakfast'   => [10, MealType::Breakfast];
        yield 'lunch start'      => [11, MealType::Lunch];
        yield 'lunch end'        => [15, MealType::Lunch];
        yield 'dinner start'     => [16, MealType::Dinner];
        yield 'dinner end'       => [21, MealType::Dinner];
        yield 'late-night snack' => [22, MealType::Snack];
        yield 'midnight snack'   => [0, MealType::Snack];
        yield 'small hours'      => [3, MealType::Snack];
    }

    #[DataProvider('hourProvider')]
    public function testDefaultForHour(int $hour, MealType $expected): void
    {
        self::assertSame($expected, MealType::defaultForHour($hour));
    }
}
