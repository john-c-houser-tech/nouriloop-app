<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\User;
use App\Service\SubscriptionService;
use App\Twig\SubscriptionExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class SubscriptionExtensionTest extends TestCase
{
    public function testReturnsFalseWhenNoUserLoggedIn(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $subscriptions = $this->createMock(SubscriptionService::class);
        $subscriptions->expects(self::never())->method('hasFeature');

        $ext = new SubscriptionExtension($subscriptions, $security);

        self::assertFalse($ext->hasFeature('cgm_correlation'));
    }

    public function testDelegatesToSubscriptionServiceForLoggedInUser(): void
    {
        $user = new User();

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $subscriptions = $this->createMock(SubscriptionService::class);
        $subscriptions->expects(self::once())
            ->method('hasFeature')
            ->with($user, 'cgm_correlation')
            ->willReturn(true);

        $ext = new SubscriptionExtension($subscriptions, $security);

        self::assertTrue($ext->hasFeature('cgm_correlation'));
    }
}
