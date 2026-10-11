<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\SubscriptionTier;
use App\Service\DemoDataSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The demo seeder builds the demo user with data across every feature, and is
 * idempotent (re-running gives the same counts, no duplicate-key errors). DB-backed,
 * so it lives in the e2e job; uses the real DemoDataSeeder service.
 */
final class DemoDataSeederTest extends WebTestCase
{
    public function testSeedsDemoUserAcrossFeaturesAndIsIdempotent(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $seeder = $container->get(DemoDataSeeder::class);

        $counts = $seeder->seed();

        self::assertGreaterThan(100, $counts['meals']);
        self::assertSame(4, $counts['favorites'], 'three meal favorites + one imported recipe');
        self::assertGreaterThan(500, $counts['health']);   // incl. dense CGM glucose
        self::assertSame(3, $counts['supplements']);
        self::assertGreaterThan(0, $counts['symptoms']);
        self::assertSame(4, $counts['goal_checkins']);

        $user = $container->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => DemoDataSeeder::EMAIL]);
        self::assertNotNull($user);
        self::assertSame(DemoDataSeeder::DISPLAY_NAME, $user->getDisplayName());

        // The permanent Loop+ comp is what keeps the review account and the
        // regenerated screenshots showing the full product (no locked upsells,
        // no exhausted Free allowance) — a regression here stays invisible in
        // the counts, so pin it explicitly.
        self::assertSame(SubscriptionTier::LoopPlus, $user->getCompTier());
        self::assertNull($user->getCompExpiresAt(), 'the comp is permanent');

        // Re-running clears + reseeds to the exact same shape.
        self::assertSame($counts, $seeder->seed());

        // The reseed keeps (or re-applies) the comp too.
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => DemoDataSeeder::EMAIL]);
        self::assertSame(SubscriptionTier::LoopPlus, $user->getCompTier());
        self::assertNull($user->getCompExpiresAt());
    }
}
