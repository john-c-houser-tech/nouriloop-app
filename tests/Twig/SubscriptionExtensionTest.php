<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\User;
use App\Service\SubscriptionService;
use App\Twig\SubscriptionExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Translation\Translator;

final class SubscriptionExtensionTest extends TestCase
{
    public function testReturnsFalseWhenNoUserLoggedIn(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $subscriptions = $this->createMock(SubscriptionService::class);
        $subscriptions->expects(self::never())->method('hasFeature');

        $ext = new SubscriptionExtension($subscriptions, $security, $this->translator('en'));

        self::assertFalse($ext->hasFeature('cgm_correlation'));
    }

    public function testDelegatesToSubscriptionServiceForLoggedInUser(): void
    {
        $user = new User();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $subscriptions = $this->createMock(SubscriptionService::class);
        $subscriptions->expects(self::once())
            ->method('hasFeature')
            ->with($user, 'cgm_correlation')
            ->willReturn(true);

        $ext = new SubscriptionExtension($subscriptions, $security, $this->translator('en'));

        self::assertTrue($ext->hasFeature('cgm_correlation'));
    }

    public function testPricesAreMonthlyAndFormattedForTheLocale(): void
    {
        $ext = new SubscriptionExtension(
            $this->createStub(SubscriptionService::class),
            $this->createStub(Security::class),
            $this->translator('en'),
        );
        self::assertSame('0', $ext->subscriptionPrice('free'));
        self::assertSame('2', $ext->subscriptionPrice('loop'));
        self::assertSame('13.50', $ext->subscriptionPrice('loop_plus'));

        $german = new SubscriptionExtension(
            $this->createStub(SubscriptionService::class),
            $this->createStub(Security::class),
            $this->translator('de'),
        );
        self::assertSame('13,50', $german->subscriptionPrice('loop_plus'));
    }

    private function translator(string $locale): Translator
    {
        return new Translator($locale);
    }
}
