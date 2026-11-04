<?php

declare(strict_types=1);

namespace App\Tests\SiteContact;

use App\SiteContact\FormToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormTokenTest extends TestCase
{
    public function testATokenIsValidOnlyWithinItsWindow(): void
    {
        $tokens = new FormToken('secret');
        $issued = 1_000_000;
        $token = $tokens->issue($issued);

        self::assertFalse($tokens->isValid($token, $issued));
        self::assertFalse($tokens->isValid($token, $issued + FormToken::MIN_AGE_SECONDS - 1));
        self::assertTrue($tokens->isValid($token, $issued + FormToken::MIN_AGE_SECONDS));
        self::assertTrue($tokens->isValid($token, $issued + FormToken::MAX_AGE_SECONDS));
        self::assertFalse($tokens->isValid($token, $issued + FormToken::MAX_AGE_SECONDS + 1));
    }

    public function testAChangedTimestampOrAnotherSecretFails(): void
    {
        $tokens = new FormToken('secret');
        [, $signature] = explode('.', $tokens->issue(1_000_000));

        self::assertFalse($tokens->isValid('999000.' . $signature, 1_001_000));
        self::assertFalse((new FormToken('other'))->isValid($tokens->issue(1_000_000), 1_001_000));
    }

    public function testAFutureOrHugeTimestampIsRefused(): void
    {
        $tokens = new FormToken('secret');

        self::assertFalse($tokens->isValid($tokens->issue(2_000_100), 2_000_000));
        self::assertFalse($tokens->isValid('99999999999999999999.x', 2_000_000));
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        new FormToken('');
    }

    #[DataProvider('junk')]
    public function testJunkIsRefused(string $token): void
    {
        self::assertFalse((new FormToken('secret'))->isValid($token, 2_000_000));
    }

    /** @return iterable<string, array{string}> */
    public static function junk(): iterable
    {
        yield 'empty' => [''];
        yield 'no dot' => ['1000000'];
        yield 'letters for time' => ['abc.def'];
        yield 'empty signature' => ['1000000.'];
    }
}
