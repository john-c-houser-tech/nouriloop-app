<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EmailDeliverabilityChecker;
use App\Tests\Stub\StubEmailDeliverabilityChecker;
use PHPUnit\Framework\TestCase;

/**
 * DB-less, network-less: the DNS branch runs through the deterministic stub
 * (real checkdnsrr is banned in tests). The typo map lives in the parent and
 * is exercised as-is.
 */
final class EmailDeliverabilityCheckerTest extends TestCase
{
    private EmailDeliverabilityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new StubEmailDeliverabilityChecker();
    }

    public function testKnownTypoDomainSuggestsTheCorrection(): void
    {
        $warning = $this->checker->assess('Grandma.Rivera@gmial.com');

        self::assertNotNull($warning);
        self::assertSame('typo', $warning->type);
        self::assertSame('gmial.com', $warning->domain);
        self::assertSame('Grandma.Rivera@gmail.com', $warning->suggestedEmail, 'local part is preserved verbatim');
    }

    public function testTypoMapIsCaseInsensitiveOnTheDomain(): void
    {
        $warning = $this->checker->assess('a@GMAIL.CON');

        self::assertNotNull($warning);
        self::assertSame('typo', $warning->type);
        self::assertSame('a@gmail.com', $warning->suggestedEmail);
    }

    public function testDomainWithoutMailServerWarns(): void
    {
        $warning = $this->checker->assess('user@' . StubEmailDeliverabilityChecker::NO_MX_DOMAIN);

        self::assertNotNull($warning);
        self::assertSame('no_mx', $warning->type);
        self::assertNull($warning->suggestedEmail);
    }

    public function testHealthyDomainPassesSilently(): void
    {
        self::assertNull($this->checker->assess('user@gmail.com'));
    }

    public function testUncheckableInputsPassSilently(): void
    {
        // Syntax is the form's Email constraint's job, not this checker's.
        self::assertNull($this->checker->assess('no-at-sign'));
        self::assertNull($this->checker->assess('trailing@'));
        self::assertNull($this->checker->assess(''));
    }
}
