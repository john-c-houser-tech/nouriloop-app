<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Dto\AdminUserFilters;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Enum\MealType;
use App\Enum\SubscriptionTier;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * /admin/users search + pagination, and that a row action still round-trips through
 * the new dropdown markup. In-process WebTestCase (no JS needed for a GET search form
 * or a plain POST dropdown item), kept under tests/E2E per the app's convention for
 * DB-needing, non-Panther tests.
 */
final class AdminUsersListTest extends WebTestCase
{
    use DbResetTrait;

    public function testSearchReturnsOnlyMatchingUsers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $admin = $this->seedUser($em, 'admin@test.local', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->seedUser($em, 'alice@test.local', ['ROLE_USER'], 'Alice Apple');
        $this->seedUser($em, 'bob@test.local', ['ROLE_USER'], 'Bob Banana');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users', ['q' => 'alice']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 account', $crawler->filter('p.text-muted')->first()->text());
        self::assertStringContainsString('alice@test.local', $crawler->filter('tbody')->text());
        self::assertStringNotContainsString('bob@test.local', $crawler->filter('tbody')->text());
        // The three new filter controls render.
        self::assertCount(1, $crawler->filter('select[name="tier"]'));
        self::assertCount(1, $crawler->filter('select[name="activity"]'));
        self::assertCount(1, $crawler->filter('select[name="status"]'));
        // Alice's row shows a plan badge (Free by default for a seeded user).
        self::assertStringContainsString('Free', $crawler->filter('tr:contains("alice@test.local")')->text());
    }

    public function testSearchTreatsLikeWildcardsLiterally(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $admin = $this->seedUser($em, 'admin@test.local', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->seedUser($em, 'a_b@test.local', ['ROLE_USER'], 'Underscore');
        $this->seedUser($em, 'axb@test.local', ['ROLE_USER'], 'Letter');

        $client->loginUser($admin);

        // "_" is a single-character wildcard in LIKE; searched for, it must match itself only.
        $crawler = $client->request('GET', '/admin/users', ['q' => 'a_b']);
        self::assertStringContainsString('a_b@test.local', $crawler->filter('tbody')->text());
        self::assertStringNotContainsString('axb@test.local', $crawler->filter('tbody')->text());

        // A bare "%" matches nobody, rather than everyone.
        $crawler = $client->request('GET', '/admin/users', ['q' => '%']);
        self::assertStringNotContainsString('@test.local', $crawler->filter('tbody')->text());
    }

    public function testPaginationSplitsAcrossPages(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $admin = $this->seedUser($em, 'admin@test.local', ['ROLE_USER', 'ROLE_ADMIN']);
        for ($i = 1; $i <= 30; $i++) {
            $this->seedUser($em, sprintf('page-user-%02d@test.local', $i), ['ROLE_USER']);
        }
        // 31 total (30 seeded + admin).

        $client->loginUser($admin);

        $page1 = $client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertCount(25, $page1->filter('table')->last()->filter('tbody tr'));
        self::assertStringContainsString('Showing 1–25 of 31', $page1->filter('body')->text());

        $page2 = $client->request('GET', '/admin/users', ['page' => 2]);
        self::assertResponseIsSuccessful();
        self::assertCount(6, $page2->filter('table')->last()->filter('tbody tr'));
        self::assertStringContainsString('Showing 26–31 of 31', $page2->filter('body')->text());
    }

    public function testPagingIsStableWhenUsersShareTheSameCreatedAtSecond(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // createdAt is second-precision; seeding a batch at the exact same instant
        // reproduces the tie a bulk import or near-simultaneous signups would hit.
        $sameInstant = new \DateTimeImmutable('2026-01-01 00:00:00');
        $emails = [];
        for ($i = 1; $i <= 30; $i++) {
            $email = sprintf('tied-user-%02d@test.local', $i);
            $emails[] = $email;
            $this->seedUserWithCreatedAt($em, $email, $sameInstant);
        }

        $users = static::getContainer()->get(UserRepository::class);
        $filters = $this->filters();
        $page1 = array_map(static fn (User $u): string => (string) $u->getEmail(), $users->searchPage($filters, 1, 25));
        $page2 = array_map(static fn (User $u): string => (string) $u->getEmail(), $users->searchPage($filters, 2, 25));

        self::assertCount(25, $page1);
        self::assertCount(5, $page2);
        // Every seeded user appears exactly once across both pages — no skip, no repeat.
        $seen = [...$page1, ...$page2];
        sort($emails);
        sort($seen);
        self::assertSame($emails, $seen);
    }

    public function testRowDropdownActionStillRoundTrips(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $admin = $this->seedUser($em, 'admin@test.local', ['ROLE_USER', 'ROLE_ADMIN']);
        $target = $this->seedUser($em, 'target@test.local', ['ROLE_USER']);
        self::assertNull($target->getCompTier());

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');
        // Both rows show an "Upgrade to Loop" item (it's offered on every Free row,
        // including the admin's own), so scope to target's specific row rather than
        // matching by button text alone. (Deeper tier-ladder behavior lives in
        // AdminTierControlsTest — this case just pins that a dropdown-item form
        // still submits after any macro re-skin.)
        $row = $crawler->filter('tr:contains("target@test.local")');
        $form = $row->selectButton('Upgrade to Loop')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($target->getId());
        self::assertSame(SubscriptionTier::Loop, $reloaded->getCompTier());
    }

    /** @param string[] $roles */
    private function seedUser(
        EntityManagerInterface $em,
        string $email,
        array $roles = ['ROLE_USER'],
        string $displayName = 'Seeded User',
    ): User {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setRoles($roles);
        $user->setActive(true);
        // Seed as verified like the grandfathered population, so the unverified banner
        // (whose form/_token a naive crawler selector would otherwise pick up) stays off
        // unless a test opts a user out. See the test-fixture rule in CLAUDE.md ▸ Auth.
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 year'));
        $user->setTimezone('America/New_York');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function filters(
        ?SubscriptionTier $tier = null,
        ?string $activity = null,
        ?string $status = null,
    ): AdminUserFilters {
        $now = new \DateTimeImmutable();

        return new AdminUserFilters($now, $now->modify('-7 days'), null, $tier, $activity, $status);
    }

    /** A meal logged $daysAgo days back — used to make a user count as "active". */
    private function seedMeal(EntityManagerInterface $em, User $user, int $daysAgo): void
    {
        $meal = new MealEntry();
        $meal->setUser($user);
        $meal->setMealType(MealType::Lunch);
        $meal->setLoggedAt(new \DateTimeImmutable("-{$daysAgo} days"));
        $em->persist($meal);
        $em->flush();
    }

    public function testTierFilterUsesEffectiveTierIncludingComp(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $paid = $this->seedUser($em, 'paid-plus@test.local');
        $paid->setSubscriptionTier(SubscriptionTier::LoopPlus);
        $paid->setSubscriptionStatus('active');

        // The case a raw-column filter gets wrong: stored Free, but comped Loop+.
        $comped = $this->seedUser($em, 'comped-plus@test.local');
        $comped->setSubscriptionTier(SubscriptionTier::Free);
        $comped->setCompTier(SubscriptionTier::LoopPlus);
        $comped->setCompExpiresAt(new \DateTimeImmutable('+1 year'));

        $free = $this->seedUser($em, 'free@test.local');
        $em->flush();

        $users = static::getContainer()->get(UserRepository::class);

        $plus = array_map(
            static fn (User $u): string => (string) $u->getEmail(),
            $users->searchPage($this->filters(SubscriptionTier::LoopPlus), 1, 25),
        );
        sort($plus);
        self::assertSame(['comped-plus@test.local', 'paid-plus@test.local'], $plus);

        $freeList = array_map(
            static fn (User $u): string => (string) $u->getEmail(),
            $users->searchPage($this->filters(SubscriptionTier::Free), 1, 25),
        );
        self::assertSame(['free@test.local'], $freeList);
        self::assertSame(1, $users->countSearch($this->filters(SubscriptionTier::Free)));
    }

    /**
     * The Loop branch has distinct exclusion logic ("Loop active AND NOT Loop+ active"),
     * and the tier filter is a hand-written mirror of SubscriptionService::effectiveTier
     * — so exercise Loop against a higher comp, an expired comp, and both sides of the
     * grace-period boundary to keep the two from silently diverging.
     */
    public function testTierFilterLoopBranchHandlesCompAndGraceEdges(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // Plainly Loop → effective Loop.
        $paidLoop = $this->seedUser($em, 'paid-loop@test.local');
        $paidLoop->setSubscriptionTier(SubscriptionTier::Loop);
        $paidLoop->setSubscriptionStatus('active');

        // Loop sub but a *higher* active comp → effective Loop+, so NOT under Loop.
        $loopButComped = $this->seedUser($em, 'loop-but-comped-plus@test.local');
        $loopButComped->setSubscriptionTier(SubscriptionTier::Loop);
        $loopButComped->setSubscriptionStatus('active');
        $loopButComped->setCompTier(SubscriptionTier::LoopPlus);
        $loopButComped->setCompExpiresAt(new \DateTimeImmutable('+1 year'));

        // Loop sub + an *expired* Loop+ comp → comp falls through, effective Loop.
        $expiredComp = $this->seedUser($em, 'expired-comp@test.local');
        $expiredComp->setSubscriptionTier(SubscriptionTier::Loop);
        $expiredComp->setSubscriptionStatus('active');
        $expiredComp->setCompTier(SubscriptionTier::LoopPlus);
        $expiredComp->setCompExpiresAt(new \DateTimeImmutable('-1 day'));

        // Canceled Loop, still inside the paid period → grace, effective Loop.
        $graceLoop = $this->seedUser($em, 'grace-loop@test.local');
        $graceLoop->setSubscriptionTier(SubscriptionTier::Loop);
        $graceLoop->setSubscriptionStatus('canceled');
        $graceLoop->setSubscriptionPeriodEnd(new \DateTimeImmutable('+5 days'));

        // Canceled Loop whose paid period has lapsed → effective Free, NOT under Loop.
        $lapsedLoop = $this->seedUser($em, 'lapsed-loop@test.local');
        $lapsedLoop->setSubscriptionTier(SubscriptionTier::Loop);
        $lapsedLoop->setSubscriptionStatus('canceled');
        $lapsedLoop->setSubscriptionPeriodEnd(new \DateTimeImmutable('-1 day'));

        $em->flush();

        $users = static::getContainer()->get(UserRepository::class);

        $loop = array_map(
            static fn (User $u): string => (string) $u->getEmail(),
            $users->searchPage($this->filters(SubscriptionTier::Loop), 1, 25),
        );
        sort($loop);
        self::assertSame(
            ['expired-comp@test.local', 'grace-loop@test.local', 'paid-loop@test.local'],
            $loop,
        );

        // The higher-comped Loop user surfaces only under Loop+.
        $plus = array_map(
            static fn (User $u): string => (string) $u->getEmail(),
            $users->searchPage($this->filters(SubscriptionTier::LoopPlus), 1, 25),
        );
        self::assertSame(['loop-but-comped-plus@test.local'], $plus);

        // The lapsed-grace user falls through to Free.
        $free = array_map(
            static fn (User $u): string => (string) $u->getEmail(),
            $users->searchPage($this->filters(SubscriptionTier::Free), 1, 25),
        );
        self::assertSame(['lapsed-loop@test.local'], $free);
    }

    public function testActivityAndStatusFiltersNarrowTheList(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // Active = logged in AND logged a meal, both within 7 days.
        $active = $this->seedUser($em, 'active@test.local');
        $active->setLastLoginAt(new \DateTimeImmutable('-1 day'));
        $this->seedMeal($em, $active, 1);

        // Logged in recently but no recent meal → inactive.
        $loggedInOnly = $this->seedUser($em, 'idle@test.local');
        $loggedInOnly->setLastLoginAt(new \DateTimeImmutable('-1 day'));

        $disabled = $this->seedUser($em, 'disabled@test.local');
        $disabled->setActive(false);

        $unverified = $this->seedUser($em, 'unverified@test.local');
        $unverified->setEmailVerifiedAt(null);
        $em->flush();

        $users = static::getContainer()->get(UserRepository::class);

        $activeList = array_map(
            static fn (User $u): string => (string) $u->getEmail(),
            $users->searchPage($this->filters(null, AdminUserFilters::ACTIVITY_ACTIVE), 1, 25),
        );
        self::assertSame(['active@test.local'], $activeList);

        // Inactive is the exact negation — everyone except the active user.
        self::assertSame(3, $users->countSearch($this->filters(null, AdminUserFilters::ACTIVITY_INACTIVE)));

        self::assertSame(1, $users->countSearch($this->filters(null, null, AdminUserFilters::STATUS_DISABLED)));
        self::assertSame(3, $users->countSearch($this->filters(null, null, AdminUserFilters::STATUS_ENABLED)));

        $unverifiedList = array_map(
            static fn (User $u): string => (string) $u->getEmail(),
            $users->searchPage($this->filters(null, null, AdminUserFilters::STATUS_UNVERIFIED), 1, 25),
        );
        self::assertSame(['unverified@test.local'], $unverifiedList);
    }

    public function testFiltersSurviveAPageBoundary(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);
        $admin = $this->seedUser($em, 'admin@test.local', ['ROLE_USER', 'ROLE_ADMIN']);
        // 30 disabled users → two pages once filtered to Disabled.
        for ($i = 1; $i <= 30; $i++) {
            $u = $this->seedUser($em, sprintf('disabled-%02d@test.local', $i), ['ROLE_USER']);
            $u->setActive(false);
        }
        $em->flush();

        $client->loginUser($admin);

        $page1 = $client->request('GET', '/admin/users', ['status' => 'disabled']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Showing 1–25 of 30', $page1->filter('body')->text());
        // The Next link must carry the status filter forward, not drop it.
        $next = $page1->filter('a:contains("Next")')->link();
        self::assertStringContainsString('status=disabled', $next->getUri());

        $page2 = $client->request('GET', $next->getUri());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Showing 26–30 of 30', $page2->filter('body')->text());
    }

    /** User has no createdAt setter (constructor-stamped) — reflection forces a tie. */
    private function seedUserWithCreatedAt(
        EntityManagerInterface $em,
        string $email,
        \DateTimeImmutable $createdAt,
    ): User {
        $user = $this->seedUser($em, $email);

        $property = new \ReflectionProperty(User::class, 'createdAt');
        $property->setValue($user, $createdAt);
        $em->flush();

        return $user;
    }
}
