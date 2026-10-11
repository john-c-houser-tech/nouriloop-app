<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Favorite;
use PHPUnit\Framework\TestCase;

final class FavoriteTest extends TestCase
{
    public function testRecordUseIncrementsCountAndStampsTime(): void
    {
        $favorite = new Favorite();
        self::assertSame(0, $favorite->getUseCount());
        self::assertNull($favorite->getLastUsedAt());

        $at = new \DateTimeImmutable('2026-06-10 12:00:00');
        $favorite->recordUse($at);

        self::assertSame(1, $favorite->getUseCount());
        self::assertSame($at, $favorite->getLastUsedAt());

        $favorite->recordUse(new \DateTimeImmutable('2026-06-11 08:00:00'));
        self::assertSame(2, $favorite->getUseCount());
    }

    public function testTokensSavedIsUseCountTimesPerAnalysis(): void
    {
        $favorite = new Favorite();
        $favorite->setTokensPerAnalysis(1800);

        self::assertSame(0, $favorite->tokensSaved());

        $favorite->recordUse();
        $favorite->recordUse();
        $favorite->recordUse();

        self::assertSame(5400, $favorite->tokensSaved());
    }

    public function testTokensSavedIsZeroWhenPerAnalysisUnknown(): void
    {
        $favorite = new Favorite();
        $favorite->recordUse();
        $favorite->recordUse();

        // Favorited before token tracking (or from a stored meal): no figure.
        self::assertSame(0, $favorite->tokensSaved());
    }
}
