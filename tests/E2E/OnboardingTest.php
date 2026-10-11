<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Service\OnboardingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * First-login onboarding overlay, end-to-end against the real DB (in-process
 * WebTestCase — kept in the e2e job for the DB, no browser): the overlay shows for a
 * fresh user, the notifications step persists + advances, completing the three required
 * steps hides it, and dismiss / Settings re-enable work.
 */
final class OnboardingTest extends WebTestCase
{
    use DbResetTrait;

    public function testFreshUserSeesOverlayWithFirstStep(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'fresh@test.local');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#onboarding-overlay'));
        self::assertSelectorTextContains('#onboarding-overlay', 'Log your first meal');
        // Only the active step renders — the optional health card isn't shown yet.
        self::assertStringNotContainsString(
            'Do you wear a glucose sensor',
            $crawler->filter('#onboarding-overlay')->text(),
        );
    }

    public function testExtraCreditStepShownAfterRequiredAndSkipClosesOverlay(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'done@test.local');
        $onboarding = static::getContainer()->get(OnboardingService::class);

        $onboarding->markStep($user, 'meal');
        $onboarding->markStep($user, 'goal');
        $onboarding->markStep($user, 'notifications');

        self::assertNotNull($user->getOnboardingCompletedAt());
        self::assertNull($user->getOnboardingHealthAt(), 'health is optional, not required to complete');

        // The overlay stays open on the optional last (health) step, now framed
        // as a question with an equal-weight "Not now" button.
        $client->loginUser($user);
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#onboarding-overlay'));
        self::assertSelectorTextContains('#onboarding-overlay', 'Do you wear a glucose sensor');
        self::assertSelectorTextContains('#onboarding-overlay', 'Not now, finish setup');

        // "Not now" (the health form with no `then` input) resolves it.
        $form = $crawler->filter('#onboarding-overlay form[action$="/onboarding/step/health"]')
            ->reduce(fn ($node) => $node->filter('input[name="then"]')->count() === 0)
            ->first()
            ->form();
        $client->submit($form);
        self::assertResponseRedirects('/');

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'done@test.local']);
        self::assertNotNull($reloaded->getOnboardingHealthAt());
        self::assertTrue($reloaded->allOnboardingStepsResolved());

        $crawler = $client->request('GET', '/');
        self::assertCount(0, $crawler->filter('#onboarding-overlay'));
    }

    public function testHealthStepConnectCtaMarksStepAndGoesToWizard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'connect@test.local');
        $onboarding = static::getContainer()->get(OnboardingService::class);

        $onboarding->markStep($user, 'meal');
        $onboarding->markStep($user, 'goal');
        $onboarding->markStep($user, 'notifications');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');

        // The primary CTA posts then=connect → the guided CGM setup.
        $form = $crawler->filter('#onboarding-overlay form[action$="/onboarding/step/health"]')
            ->reduce(fn ($node) => $node->filter('input[name="then"][value="connect"]')->count() === 1)
            ->first()
            ->form();
        $client->submit($form);
        self::assertResponseRedirects('/connect-cgm');

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'connect@test.local']);
        self::assertNotNull($reloaded->getOnboardingHealthAt());
    }

    public function testNotificationsStepFormPersistsTogglesAndAdvances(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'notif@test.local');
        $onboarding = static::getContainer()->get(OnboardingService::class);

        // Advance to the notifications step so its mini-form is the active card.
        $onboarding->markStep($user, 'meal');
        $onboarding->markStep($user, 'goal');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');
        self::assertSelectorTextContains('#onboarding-overlay', 'Turn on reminders');

        $form = $crawler->filter('#onboarding-overlay form[action$="/onboarding/notifications"]')->form();
        $form['notify_no_meals']->tick();
        $client->submit($form);
        self::assertResponseRedirects('/');

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'notif@test.local']);
        self::assertTrue($reloaded->isNotifyNoMeals());
        self::assertNotNull($reloaded->getOnboardingNotificationsAt());
        // meal+goal+notifications all done → required complete, overlay now on the
        // optional health step.
        self::assertNotNull($reloaded->getOnboardingCompletedAt());

        $crawler = $client->request('GET', '/');
        self::assertCount(1, $crawler->filter('#onboarding-overlay'));
        self::assertSelectorTextContains('#onboarding-overlay', 'Do you wear a glucose sensor');
    }

    public function testRecommendedPresetEnablesJustTheEveningNudge(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'preset@test.local');
        $onboarding = static::getContainer()->get(OnboardingService::class);

        // Advance to the notifications step; pre-enable another toggle so the
        // preset provably overrides the checkboxes rather than merging with them.
        $onboarding->markStep($user, 'meal');
        $onboarding->markStep($user, 'goal');
        $user->setNotifySupplements(true);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');
        $form = $crawler->selectButton('Recommended: just the evening reminder')->form();
        $client->submit($form);
        self::assertResponseRedirects('/');

        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'preset@test.local']);
        self::assertTrue($reloaded->isNotifyNoMeals(), 'the evening nudge is on');
        self::assertFalse($reloaded->isNotifyStaleMetrics());
        self::assertFalse($reloaded->isNotifyCalorieRange());
        self::assertFalse($reloaded->isNotifySupplements(), 'preset overrides a pre-set toggle');
        self::assertNotNull($reloaded->getOnboardingNotificationsAt(), 'the step is marked');
    }

    public function testGhostPreviewsShowDuringOnboardingOnly(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'ghost@test.local');
        $client->loginUser($user);

        // Fresh user, no data: both ghost previews render (static markup) — the
        // inflammation teaser and the glucose teaser linking to the CGM wizard.
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('scores how inflammatory', $crawler->html());
        self::assertGreaterThan(0, $crawler->filter('a[href$="/connect-cgm"]')->count());

        // Once onboarding is fully resolved the ghosts disappear (data or not).
        $onboarding = static::getContainer()->get(OnboardingService::class);
        foreach (['meal', 'goal', 'notifications', 'health'] as $step) {
            $onboarding->markStep($user, $step);
        }
        $crawler = $client->request('GET', '/');
        self::assertStringNotContainsString('scores how inflammatory', $crawler->html());
    }

    public function testDismissHidesOverlayAndSettingsReEnables(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'dismiss@test.local');
        $client->loginUser($user);

        // Dismiss via the overlay's form.
        $crawler = $client->request('GET', '/');
        $form = $crawler->filter('#onboarding-overlay form[action$="/onboarding/dismiss"]')->form();
        $client->submit($form);
        self::assertResponseRedirects('/');

        $crawler = $client->request('GET', '/');
        self::assertCount(0, $crawler->filter('#onboarding-overlay'));

        // Re-enable from Settings ▸ Notifications.
        $crawler = $client->request('GET', '/settings/notifications');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/onboarding/visibility"]')->form();
        $form['show_checklist']->tick();
        $client->submit($form);
        self::assertResponseRedirects('/settings/notifications');

        $crawler = $client->request('GET', '/');
        self::assertCount(1, $crawler->filter('#onboarding-overlay'));
    }

    /**
     * The meal step ships both device variants server-side — the inline JS only
     * chooses which one shows — so the photo CTA stays the no-JS fallback and the
     * pointer-device alternatives are always in the markup to assert against.
     */
    public function testMealStepShipsBothDeviceVariants(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'device@test.local'));

        $crawler = $client->request('GET', '/');

        // Touch variant: visible by default, the existing photo CTA.
        $touch = $crawler->filter('#onboarding-overlay [data-meal-variant="touch"]');
        self::assertCount(1, $touch);
        self::assertStringNotContainsString('d-none', (string) $touch->attr('class'));
        self::assertSame('/meal/log', $touch->filter('a')->attr('href'));

        // Pointer variant: present but hidden until the JS pointer check reveals it.
        $pointer = $crawler->filter('#onboarding-overlay [data-meal-variant="pointer"]');
        self::assertCount(1, $pointer);
        self::assertStringContainsString('d-none', (string) $pointer->attr('class'));
        self::assertStringContainsString('describe=1', (string) $pointer->filter('a')->attr('href'));
        self::assertCount(1, $pointer->filter('form[action$="/login/link/to-phone"]'));
    }

    public function testDescribeQueryParamOpensTheLogPagesDescriptionField(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $client->loginUser($this->seedUser($em, 'describe@test.local'));

        $crawler = $client->request('GET', '/meal/log');
        self::assertStringContainsString(
            'd-none',
            (string) $crawler->filter('#describe-wrap')->attr('class'),
            'collapsed by default',
        );

        $crawler = $client->request('GET', '/meal/log?describe=1');
        self::assertStringNotContainsString(
            'd-none',
            (string) $crawler->filter('#describe-wrap')->attr('class'),
            'the onboarding pointer CTA lands on an already-open field',
        );
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Seeded User');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
