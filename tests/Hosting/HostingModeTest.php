<?php

declare(strict_types=1);

namespace App\Tests\Hosting;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Hosting\HostingMode;
use App\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class HostingModeTest extends TestCase
{
    public function testHostedIsTheDefaultAndSelfIsSelfHosted(): void
    {
        self::assertTrue((new HostingMode('hosted'))->isHosted());
        self::assertTrue((new HostingMode(' SELF '))->isSelfHosted());
    }

    public function testAnUnknownModeIsAClearError(): void
    {
        $this->expectExceptionMessage('Unknown APP_HOSTING_MODE "selfhost"');
        new HostingMode('selfhost');
    }

    public function testABlankModeIsAClearErrorNotSilentlyHosted(): void
    {
        $this->expectExceptionMessage('Unknown APP_HOSTING_MODE ""');
        new HostingMode('');
    }

    public function testOnASelfHostedInstanceEveryAccountHasEveryFeature(): void
    {
        $free = new User();
        $hosted = new SubscriptionService(new MockClock(), hosting: new HostingMode('hosted'));
        $self = new SubscriptionService(new MockClock(), hosting: new HostingMode('self'));

        self::assertSame(SubscriptionTier::Free, $hosted->effectiveTier($free));
        self::assertSame(SubscriptionTier::LoopPlus, $self->effectiveTier($free));
        $features = [
            'paid_plan', 'sms', 'unlimited_checkins', 'daily_checkins',
            'checkin_delivery', 'cgm_correlation', 'ai_pattern_coach', 'predictive_help',
        ];
        foreach ($features as $feature) {
            self::assertTrue($self->hasFeature($free, $feature), $feature);
        }
        self::assertFalse($self->hasFeature($free, 'no_such_feature'), 'unknown keys still deny');
    }
}
