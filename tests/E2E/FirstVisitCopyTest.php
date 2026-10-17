<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What a first-time visitor sees on the two pages ads and links send them to
 * (brand review 2026-10-08): the login panel greets nobody as a returning user,
 * and the pricing page states the Free plan before the paid cards — with a
 * sign-up button for visitors, a "current plan" badge for Free users, and
 * nothing for paying users. In-process WebTestCase (needs the DB for the
 * signed-in cases).
 */
final class FirstVisitCopyTest extends WebTestCase
{
    use DbResetTrait;

    public function testLoginPanelDoesNotWelcomeAVisitorBack(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h2', 'Sign in');
        self::assertSelectorTextNotContains('body', 'Welcome back');
    }

    public function testAVisitorSeesTheFreePlanAndASignUpButton(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
        $strip = $crawler->filter('#free-plan-strip');
        self::assertStringContainsString('Free · $0', $strip->text());
        self::assertStringContainsString('10 AI analyses a month', $strip->text());
        self::assertStringContainsString('first 6 goal check-ins', $strip->text());
        self::assertSame('/register', $strip->filter('a')->attr('href'));
        self::assertSame(0, $strip->filter('.badge')->count());
    }

    /** Monthly only since the tier rework; prices come from the tier enum. */
    public function testThePaidPlansAreMonthlyWithTheirDescriptors(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/pricing');

        $loop = $crawler->filter('#plan-loop')->text();
        self::assertStringContainsString('Bring your own AI key', $loop);
        self::assertStringContainsString('$2 /mo', $loop);
        self::assertStringContainsString('Until you add your key, Loop includes 10 AI analyses a month', $loop);

        $plus = $crawler->filter('#plan-loop-plus')->text();
        self::assertStringContainsString('AI included', $plus);
        self::assertStringContainsString('$13.50 /mo', $plus);
        self::assertStringContainsString('120 AI analyses a month included', $plus);
        self::assertStringContainsString('by text (SMS)', $plus);
        self::assertStringNotContainsString('by text', $loop, 'SMS is Loop+ only');

        self::assertSame(0, $crawler->filter('#billing-annual')->count(), 'no annual toggle');
    }

    public function testAFreeUserSeesTheStripAsTheirCurrentPlan(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser(null));
        $crawler = $client->request('GET', '/pricing');

        $strip = $crawler->filter('#free-plan-strip');
        self::assertSame(0, $strip->filter('a')->count(), 'no sign-up button for someone already signed up');
        self::assertSame('Your current plan', trim($strip->filter('.badge')->text()));
    }

    public function testAPayingUserSeesNeitherButtonNorBadgeOnTheStrip(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser(SubscriptionTier::Loop));
        $crawler = $client->request('GET', '/pricing');

        $strip = $crawler->filter('#free-plan-strip');
        self::assertSame(0, $strip->filter('a')->count());
        self::assertSame(0, $strip->filter('.badge')->count());
    }

    private function seedUser(?SubscriptionTier $comp): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('visitor@test.local');
        $user->setDisplayName('Visitor');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setOnboardingDismissed(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        if ($comp !== null) {
            $user->setCompTier($comp);
        }
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
