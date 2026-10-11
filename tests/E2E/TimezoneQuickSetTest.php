<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Dto\GoalDefinition;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalFrequency;
use App\Enum\GoalMetric;
use App\Enum\UnitSystem;
use App\Service\GoalScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The timezone-detection fix + mismatch repair banner (in-process, real DB):
 * registration stores a posted browser timezone (the hidden field the register
 * page's inline JS fills — Stimulus never loads in this app, so the old
 * data-controller wiring silently left every account on the LA default), the
 * banner markup rides on authenticated pages for its JS to reveal, and the
 * one-click quick-set route validates + saves the suggested zone.
 */
final class TimezoneQuickSetTest extends WebTestCase
{
    use DbResetTrait;

    public function testRegistrationStoresPostedTimezoneAndDerivesUnits(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'Berlin Person',
            'registration_form[email]' => 'berlin@test.local',
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
            'registration_form[timezone]' => 'Europe/Berlin',
        ]);

        $this->assertResponseRedirects('/');
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'berlin@test.local']);
        self::assertSame('Europe/Berlin', $user->getTimezone());
        self::assertSame(UnitSystem::Metric, $user->getUnitSystem(), 'units derive from the real zone');
    }

    public function testRegistrationWithBlankTimezoneKeepsTheDefault(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $client->request('GET', '/register');
        $client->submitForm('Create Account', [
            'registration_form[displayName]' => 'No JS Person',
            'registration_form[email]' => 'nojs@test.local',
            'registration_form[plainPassword][first]' => 'password123',
            'registration_form[plainPassword][second]' => 'password123',
            'registration_form[timezone]' => '',
        ]);

        $this->assertResponseRedirects('/');
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'nojs@test.local']);
        self::assertSame(User::DEFAULT_TIMEZONE, $user->getTimezone());
    }

    public function testBannerMarkupRendersHiddenOnAuthenticatedPages(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'banner@test.local');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');

        $banner = $crawler->filter('#tz-banner');
        self::assertCount(1, $banner, 'the mismatch banner rides on authenticated pages');
        self::assertSame('America/New_York', $banner->attr('data-account-tz'));
        self::assertNotSame('', (string) $banner->attr('data-csrf'), 'JS needs the token to build the form');
        self::assertStringContainsString(
            'd-none',
            (string) $banner->attr('class'),
            'hidden until JS confirms a mismatch',
        );

        // Regression guards: the banner is on EVERY authenticated page, so it must
        // not contribute a <form> (it would become the page's first form and break
        // naive crawler form selection — it did) nor carry .alert (it would match
        // flash-message assertions). Its form is built in JS at click time.
        self::assertCount(0, $banner->filter('form'));
        self::assertStringNotContainsString('alert', (string) $banner->attr('class'));
    }

    public function testQuickSetSavesAValidBrowserZone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'quick@test.local');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');
        // The banner has no server-rendered form (see the markup test) — POST the
        // way its JS does, with the token from the data attribute.
        $client->request('POST', '/settings/timezone/quick', [
            '_token' => (string) $crawler->filter('#tz-banner')->attr('data-csrf'),
            'timezone' => 'Europe/Madrid',
        ]);

        $this->assertResponseRedirects();
        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'quick@test.local']);
        self::assertSame('Europe/Madrid', $reloaded->getTimezone());
    }

    public function testQuickSetReschedulesActiveGoalsIntoTheNewZone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'goals@test.local');

        // A daily 8 PM check-in whose nextRunAt was materialized in the OLD
        // (New York) frame — after repairing to Los Angeles it must move, or the
        // next run fires three hours early by the user's new local clock.
        $goal = new NutritionGoal();
        $goal->setUser($user)
            ->setName('Eat more fiber')
            ->setDefinition(new GoalDefinition(
                GoalMetric::Fiber,
                GoalDirection::AtLeast,
                GoalAggregation::DailyAverage,
                25.0,
                7,
            ))
            ->setEvaluationPromptTemplate('Evaluate the goal.')
            ->setFrequency(GoalFrequency::Daily)
            ->setHour(20)
            ->setMinute(0);
        $scheduler = static::getContainer()->get(GoalScheduler::class);
        $staleNextRun = $scheduler->computeNextRun($goal, new \DateTimeImmutable());
        $goal->setNextRunAt($staleNextRun);
        $em->persist($goal);
        $em->flush();
        $goalId = $goal->getId();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');
        $client->request('POST', '/settings/timezone/quick', [
            '_token' => (string) $crawler->filter('#tz-banner')->attr('data-csrf'),
            'timezone' => 'America/Los_Angeles',
        ]);

        $em->clear();
        $reloaded = $em->getRepository(NutritionGoal::class)->find($goalId);
        $rescheduled = $reloaded->getNextRunAt();
        self::assertNotNull($rescheduled);
        self::assertNotEquals(
            $staleNextRun->format(\DateTimeImmutable::ATOM),
            $rescheduled->format(\DateTimeImmutable::ATOM),
            'nextRunAt must be recomputed in the new zone',
        );
        // The recomputed instant is the user's local 8 PM in the NEW zone: LA is
        // 3 hours behind NY, so the absolute instant lands 3 hours later
        // (modulo a day roll — compare the instant's LA wall-clock instead).
        self::assertSame(
            '20:00',
            $rescheduled->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('H:i'),
        );
    }

    public function testQuickSetIgnoresAnInvalidZone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'invalid@test.local');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/');
        $client->request('POST', '/settings/timezone/quick', [
            '_token' => (string) $crawler->filter('#tz-banner')->attr('data-csrf'),
            'timezone' => 'Mars/Olympus_Mons',
        ]);

        $this->assertResponseRedirects();
        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'invalid@test.local']);
        self::assertSame('America/New_York', $reloaded->getTimezone(), 'junk zones are ignored');
    }

    public function testQuickSetRejectsABadCsrfToken(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $user = $this->seedUser($em, 'csrf@test.local');

        $client->loginUser($user);
        $client->request('POST', '/settings/timezone/quick', [
            '_token' => 'forged',
            'timezone' => 'Europe/Madrid',
        ]);

        $this->assertResponseRedirects();
        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'csrf@test.local']);
        self::assertSame('America/New_York', $reloaded->getTimezone(), 'a forged token changes nothing');
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
        // Fixture rule (see CLAUDE.md): verified unless the test targets the
        // unverified state, so the verification banner can't confuse crawlers.
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
