<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Dto\GoalDefinition;
use App\Entity\GoalCheckin;
use App\Entity\Notification;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalCheckinStatus;
use App\Enum\GoalDirection;
use App\Enum\GoalFrequency;
use App\Enum\GoalMetric;
use App\Enum\GoalOutputFormat;
use App\Enum\SubscriptionTier;
use App\Service\CheckinAllowance;
use App\Service\GoalFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Goal & notification tiering enforcement, end-to-end against the real DB
 * (in-process WebTestCase + CommandTester, same rationale as
 * PurgeDeletedAccountsCommandTest): the cron's per-goal checks (Free allowance
 * pause at exactly FREE_ALLOWANCE, no_data runs don't count, daily→weekly
 * persist-migrate, entitled users unaffected), the resume path for an upgraded
 * user + the server-side resume guard, the GoalFactory daily-cadence backstop,
 * and the tier-aware UX on the goal page / manual form / settings / pricing.
 */
final class GoalTieringTest extends WebTestCase
{
    use DbResetTrait;

    private const NOW = '2026-07-22 12:00:00';

    public function testFreeOwnerWithExhaustedAllowanceIsPausedNotEvaluated(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $user = $this->seedUser($em, 'free@test.local');
        $goal = $this->seedDueGoal($em, $user);
        $this->seedCheckins($em, $goal, CheckinAllowance::FREE_ALLOWANCE, GoalCheckinStatus::OnTrack);

        $this->runCheckins();

        $em->clear();
        $goal = $em->getRepository(NutritionGoal::class)->find($goal->getId());
        self::assertFalse($goal->isActive(), 'exhausted Free goal must be paused');
        self::assertNull($goal->getNextRunAt(), 'paused goal must not stay scheduled');
        self::assertCount(
            CheckinAllowance::FREE_ALLOWANCE,
            $em->getRepository(GoalCheckin::class)->findAll(),
            'no new check-in may be evaluated past the allowance',
        );

        $notices = $em->getRepository(Notification::class)->findAll();
        self::assertCount(1, $notices);
        self::assertStringContainsString('Check-ins paused', $notices[0]->getTitle());

        // A forced re-run on the already-paused goal (--goal bypasses findDue)
        // must not stack a duplicate "paused" notice.
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:run-goal-checkins'),
        );
        $tester->execute(['--goal' => (string) $goal->getId(), '--now' => self::NOW]);
        $tester->assertCommandIsSuccessful();
        self::assertCount(1, $em->getRepository(Notification::class)->findAll());
    }

    public function testNoDataCheckinsDoNotConsumeTheAllowance(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $user = $this->seedUser($em, 'free@test.local');
        $goal = $this->seedDueGoal($em, $user);
        // 5 evaluated + 3 no_data: evaluated count is below the allowance of 6,
        // so the run must proceed (no_data/error runs make no Claude call and
        // deliberately don't count).
        $this->seedCheckins($em, $goal, 5, GoalCheckinStatus::OnTrack);
        $this->seedCheckins($em, $goal, 3, GoalCheckinStatus::NoData);

        $this->runCheckins();

        $em->clear();
        $goal = $em->getRepository(NutritionGoal::class)->find($goal->getId());
        self::assertTrue($goal->isActive());
        self::assertNotNull($goal->getNextRunAt(), 'goal must be rescheduled, not paused');
        self::assertCount(9, $em->getRepository(GoalCheckin::class)->findAll(), 'a new check-in must be recorded');
    }

    public function testEntitledOwnerIsUnaffectedPastSix(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $user = $this->seedUser($em, 'loop@test.local', comp: SubscriptionTier::Loop);
        $goal = $this->seedDueGoal($em, $user);
        $this->seedCheckins($em, $goal, CheckinAllowance::FREE_ALLOWANCE + 4, GoalCheckinStatus::OnTrack);

        $this->runCheckins();

        $em->clear();
        $goal = $em->getRepository(NutritionGoal::class)->find($goal->getId());
        self::assertTrue($goal->isActive());
        self::assertNotNull($goal->getNextRunAt());
        self::assertCount(
            CheckinAllowance::FREE_ALLOWANCE + 5,
            $em->getRepository(GoalCheckin::class)->findAll(),
            'unlimited_checkins users evaluate normally past the Free allowance',
        );
    }

    public function testDailyGoalOfNonEntitledOwnerIsPersistMigratedToWeekly(): void
    {
        $client = static::createClient();
        $em = $this->em();

        // A Free owner (e.g. a lapsed subscriber): daily_checkins is a paid feature.
        $user = $this->seedUser($em, 'lapsed@test.local');
        $goal = $this->seedDueGoal($em, $user, GoalFrequency::Daily);

        $this->runCheckins();

        $em->clear();
        $goal = $em->getRepository(NutritionGoal::class)->find($goal->getId());
        self::assertSame(GoalFrequency::Weekly, $goal->getFrequency(), 'daily must persist-migrate to weekly');
        self::assertNotNull($goal->getDayOfWeek());
        self::assertTrue($goal->isActive());
        self::assertNotNull($goal->getNextRunAt());
        self::assertCount(1, $em->getRepository(GoalCheckin::class)->findAll(), 'the run itself still evaluates');

        $notices = $em->getRepository(Notification::class)->findAll();
        self::assertCount(1, $notices);
        self::assertStringContainsString('checks in weekly', $notices[0]->getTitle());
    }

    public function testLoopPlusDailyGoalIsUntouched(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $user = $this->seedUser($em, 'plus@test.local', comp: SubscriptionTier::LoopPlus);
        $goal = $this->seedDueGoal($em, $user, GoalFrequency::Daily);

        $this->runCheckins();

        $em->clear();
        $goal = $em->getRepository(NutritionGoal::class)->find($goal->getId());
        self::assertSame(GoalFrequency::Daily, $goal->getFrequency());
        self::assertCount(0, $em->getRepository(Notification::class)->findAll(), 'no migration notice');
    }

    public function testPausedGoalResumesForUpgradedUserAndTheGuardBlocksAfterALapse(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $user = $this->seedUser($em, 'upgraded@test.local');
        $goal = $this->seedDueGoal($em, $user);
        $this->seedCheckins($em, $goal, CheckinAllowance::FREE_ALLOWANCE, GoalCheckinStatus::OnTrack);
        // Paused by the allowance (same state the cron writes).
        $goal->setActive(false)->setNextRunAt(null);
        $em->flush();

        $client->loginUser($user);

        // Exhausted + Free: the page offers an upgrade CTA instead of Resume.
        $crawler = $client->request('GET', '/goals/' . $goal->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Upgrade to resume check-ins', $crawler->text());
        self::assertSame(
            0,
            $crawler->filter('form[action$="/toggle"]')->count(),
            'no Resume form while the allowance is exhausted',
        );

        // Upgrade (Loop comp) → the normal Resume button is back; resuming re-arms.
        $user = $em->getRepository(User::class)->find($user->getId());
        $user->setCompTier(SubscriptionTier::Loop);
        $em->flush();

        $crawler = $client->request('GET', '/goals/' . $goal->getId());
        $form = $crawler->filter('form[action$="/toggle"]')->form();

        // Lapse the comp between page load and submit: the server-side guard must
        // block the resume (the cron would otherwise instantly re-pause + re-notify).
        // Re-fetch — the client request reset the EM, detaching the prior instance.
        $user = $em->getRepository(User::class)->find($user->getId());
        $user->setCompTier(null);
        $em->flush();
        $client->submit($form);
        $client->followRedirect();
        $em->clear();
        $goal = $em->getRepository(NutritionGoal::class)->find($goal->getId());
        self::assertFalse($goal->isActive(), 'a lapsed, exhausted user must not re-arm the goal');

        // Re-grant and resume for real.
        $user = $em->getRepository(User::class)->find($user->getId());
        $user->setCompTier(SubscriptionTier::Loop);
        $em->flush();
        $crawler = $client->request('GET', '/goals/' . $goal->getId());
        $client->submit($crawler->filter('form[action$="/toggle"]')->form());

        $em->clear();
        $goal = $em->getRepository(NutritionGoal::class)->find($goal->getId());
        self::assertTrue($goal->isActive(), 'an upgraded user resumes via the existing toggle');
        self::assertNotNull($goal->getNextRunAt(), 'resume re-arms the schedule');
    }

    public function testGoalPageShowsTheAllowanceCounterForFreeUsersOnly(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $free = $this->seedUser($em, 'free@test.local');
        $goal = $this->seedDueGoal($em, $free);
        $this->seedCheckins($em, $goal, 2, GoalCheckinStatus::OnTrack);

        $client->loginUser($free);
        $crawler = $client->request('GET', '/goals/' . $goal->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2 of 6 included check-ins used', $crawler->text());

        // An entitled user sees no counter.
        $plus = $this->seedUser($em, 'plus@test.local', comp: SubscriptionTier::LoopPlus);
        $plusGoal = $this->seedDueGoal($em, $plus);
        $client->loginUser($plus);
        $crawler = $client->request('GET', '/goals/' . $plusGoal->getId());
        self::assertStringNotContainsString('included check-ins used', $crawler->text());
    }

    public function testManualFormLocksDailyCadenceForNonLoopPlus(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $free = $this->seedUser($em, 'free@test.local');
        $client->loginUser($free);
        $crawler = $client->request('GET', '/goals/new/manual');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#frequency option[value="daily"][disabled]')->count());
        self::assertStringContainsString('Daily check-ins are part of the paid plans', $crawler->text());

        // Both paid plans have daily check-ins since the tier rework.
        foreach ([SubscriptionTier::Loop, SubscriptionTier::LoopPlus] as $tier) {
            $paid = $this->seedUser($em, $tier->value . '@test.local', comp: $tier);
            $client->loginUser($paid);
            $crawler = $client->request('GET', '/goals/new/manual');
            self::assertSame(0, $crawler->filter('#frequency option[value="daily"][disabled]')->count(), $tier->value);
        }
    }

    public function testGoalFactoryCoercesDailyToWeeklyForNonEntitledUsers(): void
    {
        static::bootKernel();
        $em = $this->em();

        $free = $this->seedUser($em, 'free@test.local');
        /** @var GoalFactory $factory */
        $factory = static::getContainer()->get(GoalFactory::class);

        $goal = $factory->create(
            $free,
            $this->definition(),
            'Tampered daily goal',
            GoalFrequency::Daily,
            null,
            18,
            0,
            GoalOutputFormat::Summary,
        );

        self::assertSame(GoalFrequency::Weekly, $goal->getFrequency(), 'the factory is the server-side backstop');
        self::assertNotNull($goal->getDayOfWeek());
    }

    public function testSettingsNotificationsShowsSmsUpsellForFreeAndKeepsStoredPhone(): void
    {
        $client = static::createClient();
        $em = $this->em();

        // A Free user with a phone stored from an earlier entitled period: the SMS
        // controls are omitted, and saving the form must NOT wipe the number.
        $free = $this->seedUser($em, 'free@test.local');
        $free->setPhoneNumber('+15035551234');
        $em->flush();

        $client->loginUser($free);
        $crawler = $client->request('GET', '/settings/notifications');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#sms-upsell')->count());
        self::assertSame(0, $crawler->filter('input[type="tel"]')->count());

        $client->submit($crawler->filter('button[type="submit"]')->form());
        $em->clear();
        $free = $em->getRepository(User::class)->find($free->getId());
        self::assertSame('+15035551234', $free->getPhoneNumber(), 'an unrendered field must not clobber the phone');

        // SMS is Loop+ only (Twilio fees), so Loop sees the upsell too…
        $loop = $this->seedUser($em, 'loop@test.local', comp: SubscriptionTier::Loop);
        $client->loginUser($loop);
        $crawler = $client->request('GET', '/settings/notifications');
        self::assertSame(1, $crawler->filter('#sms-upsell')->count());
        self::assertSame(0, $crawler->filter('input[type="tel"]')->count());

        // …and Loop+ keeps the full phone + consent controls.
        $plus = $this->seedUser($em, 'plus@test.local', comp: SubscriptionTier::LoopPlus);
        $client->loginUser($plus);
        $crawler = $client->request('GET', '/settings/notifications');
        self::assertSame(0, $crawler->filter('#sms-upsell')->count());
        self::assertSame(1, $crawler->filter('input[type="tel"]')->count());
    }

    public function testPricingPageCarriesTheNewTierCopy(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
        $body = $client->getCrawler()->text();
        self::assertStringContainsString('Daily or weekly goal check-ins, delivered by email', $body);
        self::assertStringContainsString('Daily digest and goal check-ins by text (SMS)', $body);
        self::assertStringContainsString('Unlimited AI analyses on your own key', $body);
        // The free_note footer discloses BOTH Free allowances: the 6 lifetime
        // check-ins and the 10 monthly AI analyses (meal photos or recipe
        // imports share one bucket since the recipe import shipped).
        self::assertStringContainsString('first 6 goal check-ins', $body);
        self::assertStringContainsString('10 AI analyses each month', $body);
        self::assertStringContainsString('recipe imports', $body);
        self::assertStringNotContainsString('Email/SMS Reports', $body, 'the inaccurate Loop+ row is retired');
        // Favorites are free on every tier (the 'favorites' entitlement key was
        // retired 2026-07-22 as never-enforced): the Loop card must not sell
        // "Favorite Meals" as a paid feature, and the free_note states the
        // truth instead. (Case matters: the retired row was title-case
        // "Favorite Meals"; the free_note sentence says "Favorite meals".)
        self::assertStringNotContainsString('Favorite Meals', $body, 'the never-enforced Loop row is retired');
        self::assertStringContainsString('Favorite meals are included on every plan', $body);
        // "What if?" predictions shipped 2026-06-26 (Loop+, /insights/predict) — the
        // page must sell it as live, not "coming soon" (stale-copy catch, v2.5.0).
        self::assertStringContainsString('“What if?” predictions', $body);
        self::assertStringNotContainsString('coming soon', $body);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        return $em;
    }

    private function runCheckins(): void
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:run-goal-checkins'),
        );
        $tester->execute(['--now' => self::NOW]);
        $tester->assertCommandIsSuccessful();
    }

    private function definition(): GoalDefinition
    {
        return new GoalDefinition(
            GoalMetric::Fiber,
            GoalDirection::AtLeast,
            GoalAggregation::DailyAverage,
            28.0,
            7,
        );
    }

    private function seedDueGoal(
        EntityManagerInterface $em,
        User $user,
        GoalFrequency $frequency = GoalFrequency::Weekly,
    ): NutritionGoal {
        $goal = (new NutritionGoal())
            ->setUser($user)
            ->setName('Eat more fiber')
            ->setDefinition($this->definition())
            ->setEvaluationPromptTemplate('Evaluate the goal.')
            ->setFrequency($frequency)
            ->setDayOfWeek($frequency === GoalFrequency::Weekly ? 7 : null)
            ->setNextRunAt((new \DateTimeImmutable(self::NOW))->modify('-1 hour'));
        $em->persist($goal);
        $em->flush();

        return $goal;
    }

    private function seedCheckins(
        EntityManagerInterface $em,
        NutritionGoal $goal,
        int $count,
        GoalCheckinStatus $status,
    ): void {
        for ($i = 0; $i < $count; ++$i) {
            $checkin = (new GoalCheckin())
                ->setGoal($goal)
                ->setStatus($status)
                ->setRunAt((new \DateTimeImmutable(self::NOW))->modify(sprintf('-%d days', $count - $i)));
            $em->persist($checkin);
        }
        $em->flush();
    }

    private function seedUser(EntityManagerInterface $em, string $email, ?SubscriptionTier $comp = null): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Seeded User');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        if ($comp !== null) {
            $user->setCompTier($comp); // permanent comp
        }

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
