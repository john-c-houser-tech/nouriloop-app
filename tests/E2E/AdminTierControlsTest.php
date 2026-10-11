<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Admin one-step tier ladder (in-process, real DB): Upgrade/Downgrade adjust the
 * COMP only (permanent, billing-independent — the beta-grandfathering mechanism),
 * bounded below by the user's paid Stripe tier, replacing the retired per-user
 * AI Coach toggle (services now follow the tier).
 */
final class AdminTierControlsTest extends WebTestCase
{
    use DbResetTrait;

    public function testUpgradeClimbsTheLadderOneStepAtATime(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $admin = $this->seedUser($em, 'admin@test.local', admin: true);
        $target = $this->seedUser($em, 'freebie@test.local');
        $client->loginUser($admin);

        // Free → Loop.
        $this->clickRowAction($client, 'Upgrade to Loop', $target->getId());
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($target->getId());
        self::assertSame(SubscriptionTier::Loop, $fresh->getCompTier());
        self::assertNull($fresh->getCompExpiresAt(), 'admin upgrades grant PERMANENT comps');

        // Loop → Loop+; then the Upgrade item disappears at the top.
        $this->clickRowAction($client, 'Upgrade to Loop+', $target->getId());
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($target->getId());
        self::assertSame(SubscriptionTier::LoopPlus, $fresh->getCompTier());

        $html = $client->request('GET', '/admin/users')->html();
        self::assertStringNotContainsString('Upgrade to', $this->rowFor($html, 'freebie@test.local'));
    }

    public function testDowngradeStepsDownAndClearsTheCompAtFree(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $admin = $this->seedUser($em, 'admin@test.local', admin: true);
        $target = $this->seedUser($em, 'comped@test.local');
        $target->setCompTier(SubscriptionTier::LoopPlus);
        $em->flush();
        $client->loginUser($admin);

        // Loop+ → Loop: still comped, one step down.
        $this->clickRowAction($client, 'Downgrade to Loop', $target->getId());
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($target->getId());
        self::assertSame(SubscriptionTier::Loop, $fresh->getCompTier());

        // Loop → Free: the comp is cleared entirely, not stored as "comp Free".
        $this->clickRowAction($client, 'Downgrade to Free', $target->getId());
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($target->getId());
        self::assertNull($fresh->getCompTier());
        self::assertNull($fresh->getCompExpiresAt());

        $html = $client->request('GET', '/admin/users')->html();
        self::assertStringNotContainsString('Downgrade to', $this->rowFor($html, 'comped@test.local'));
    }

    public function testDowngradeKeepsATimeLimitedCompsExpiry(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $admin = $this->seedUser($em, 'admin@test.local', admin: true);
        // A beta-style expiring grant: downgrading it must NOT quietly extend
        // it to a permanent comp (Copilot catch on PR #179).
        $expiry = new \DateTimeImmutable('+30 days');
        $target = $this->seedUser($em, 'beta@test.local');
        $target->setCompTier(SubscriptionTier::LoopPlus);
        $target->setCompExpiresAt($expiry);
        $em->flush();
        $client->loginUser($admin);

        $this->clickRowAction($client, 'Downgrade to Loop', $target->getId());
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($target->getId());
        self::assertSame(SubscriptionTier::Loop, $fresh->getCompTier());
        self::assertSame(
            $expiry->format('Y-m-d H:i'),
            $fresh->getCompExpiresAt()?->format('Y-m-d H:i'),
            'lowering a time-limited comp preserves its expiry',
        );
    }

    public function testDowngradeIsBoundedByThePaidStripeTier(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $admin = $this->seedUser($em, 'admin@test.local', admin: true);
        // A paying Loop subscriber holding a Loop+ comp on top.
        $payer = $this->seedUser($em, 'payer@test.local');
        $payer->setSubscriptionTier(SubscriptionTier::Loop);
        $payer->setSubscriptionStatus('active');
        $payer->setCompTier(SubscriptionTier::LoopPlus);
        $em->flush();
        $client->loginUser($admin);

        // Grab the rendered Downgrade form BEFORE acting — resubmitting it later
        // is the stale-form scenario the server-side floor guard exists for.
        $crawler = $client->request('GET', '/admin/users');
        $staleForm = $crawler
            ->filter(sprintf('form[action$="/admin/users/%d/tier/down"]', $payer->getId()))
            ->form();

        // Loop+ (comp) → Loop (paid floor): the now-redundant comp is cleared.
        $client->submit($staleForm);
        $this->assertResponseRedirects('/admin/users');
        $client->followRedirect(); // consume the success flash
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($payer->getId());
        self::assertNull($fresh->getCompTier(), 'a comp at/below the paid tier is cleared, not stored');
        self::assertSame(SubscriptionTier::Loop, $fresh->getSubscriptionTier());

        // At the paid floor the menu shows a disabled explainer instead of a form.
        $row = $this->rowFor($client->request('GET', '/admin/users')->html(), 'payer@test.local');
        self::assertStringContainsString('Downgrade (paid via Stripe)', $row);
        self::assertStringNotContainsString('Downgrade to', $row);

        // The stale form (valid CSRF, outdated state) is refused server-side —
        // going below the paid tier would mean touching Stripe billing.
        $client->submit($staleForm);
        $this->assertResponseRedirects('/admin/users');
        self::assertStringContainsString(
            'managed in Stripe',
            $client->followRedirect()->html(),
        );
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($payer->getId());
        self::assertSame(SubscriptionTier::Loop, $fresh->getSubscriptionTier());
        self::assertNull($fresh->getCompTier());
    }

    public function testTierRoutesRequireAdmin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $victim = $this->seedUser($em, 'victim@test.local');
        $client->loginUser($this->seedUser($em, 'pleb@test.local'));

        $client->request('POST', '/admin/users/' . $victim->getId() . '/tier/up');
        $this->assertResponseStatusCodeSame(403);
        $em->clear();
        self::assertNull($em->getRepository(User::class)->find($victim->getId())->getCompTier());
    }

    /**
     * Submits the named tier-action button inside the target user's row form —
     * exercising the real dropdown markup + CSRF token, not a hand-built POST.
     */
    private function clickRowAction(mixed $client, string $label, int $userId): void
    {
        $crawler = $client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $direction = str_starts_with($label, 'Upgrade') ? 'up' : 'down';
        $selector = sprintf('form[action$="/admin/users/%d/tier/%s"]', $userId, $direction);
        self::assertStringContainsString($label, $crawler->filter($selector)->text());
        $client->submit($crawler->filter($selector)->form());
        $this->assertResponseRedirects('/admin/users');
        $client->followRedirect();
    }

    /**
     * The <tr> chunk containing the given email — keeps assertions row-scoped.
     * Chunk 0 (everything before the first <tr>) is skipped: a pending flash
     * message naming the email would otherwise shadow the real row.
     */
    private function rowFor(string $html, string $email): string
    {
        $rows = preg_split('/<tr[\s>]/', $html) ?: [];
        foreach (array_slice($rows, 1) as $row) {
            if (str_contains($row, $email)) {
                return $row;
            }
        }
        self::fail('No row found for ' . $email);
    }

    private function seedUser(EntityManagerInterface $em, string $email, bool $admin = false): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Tier Tester');
        $user->setRoles($admin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
