<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Service\EmailDeliverabilityChecker;

/**
 * Test-env replacement (config/services_test.yaml): the typo map is inherited
 * verbatim, but the DNS hook is deterministic — tests/CI make no network
 * calls (same rule as the disabled HIBP check). Exactly one magic domain
 * fails; everything else "has a mail server".
 */
final class StubEmailDeliverabilityChecker extends EmailDeliverabilityChecker
{
    public const NO_MX_DOMAIN = 'no-mail-server.test';

    protected function domainAccepts(string $domain): bool
    {
        return $domain !== self::NO_MX_DOMAIN;
    }
}
