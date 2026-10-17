<?php

declare(strict_types=1);

namespace App\Hosting;

use Symfony\Bundle\FrameworkBundle\Routing\Attribute\AsRoutingConditionService;

/**
 * Whether this instance is NouriLoop's hosted service or someone's self-hosted copy
 * (`APP_HOSTING_MODE`, ADR 0003). A self-hosted instance has no billing: every account
 * has every feature ({@see \App\Service\SubscriptionService::effectiveTier()}), there
 * are no allowances, and the pricing, checkout, portal and Stripe-webhook routes don't
 * match (their `condition` asks `service('hosting').isHosted()`), so they answer 404
 * while `path()` can still generate them for templates.
 */
#[AsRoutingConditionService(alias: 'hosting')]
final class HostingMode
{
    public const HOSTED = 'hosted';
    public const SELF = 'self';

    private readonly bool $selfHosted;

    public function __construct(string $hostingMode)
    {
        $mode = strtolower(trim($hostingMode));
        // Blank is an error too: a deployment that set the variable empty should hear about
        // it, not silently get billing (Copilot catch on #245).
        if (!\in_array($mode, [self::HOSTED, self::SELF], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown APP_HOSTING_MODE "%s"; use "%s" or "%s".',
                $hostingMode,
                self::HOSTED,
                self::SELF,
            ));
        }
        $this->selfHosted = $mode === self::SELF;
    }

    public function isSelfHosted(): bool
    {
        return $this->selfHosted;
    }

    public function isHosted(): bool
    {
        return !$this->selfHosted;
    }
}
