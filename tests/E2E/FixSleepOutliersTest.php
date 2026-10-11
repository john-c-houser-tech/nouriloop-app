<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\HealthMetric;
use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Repository\HealthMetricRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `app:fix-sleep-outliers`, end-to-end against the real DB (in-process, not
 * Panther — so it lives in E2E for the DB but excludes from the DB-less unit job).
 *
 * Exercises the *apply* path specifically: a green --dry-run alone would not catch
 * a write-path fatal (cf. the DBAL 4 array-param bug fixed in PR #104).
 */
final class FixSleepOutliersTest extends WebTestCase
{
    use DbResetTrait;

    public function testApplyReplacesOutlierNightWithAverageAndLeavesNormalNightsAlone(): void
    {
        $kernel = self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'sleeper@test.local');
        // Three normal 6h nights + one 11.67h outlier (all as in-bed segments).
        $this->seedSleepNight($em, $user, '2025-11-01', 360.0);
        $this->seedSleepNight($em, $user, '2025-11-02', 360.0);
        $this->seedSleepNight($em, $user, '2025-11-03', 360.0);
        $this->seedSleepNight($em, $user, '2025-11-05', 700.0);
        $em->flush();

        $tester = $this->runCmd('sleeper@test.local');
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Corrected 1 night', $tester->getDisplay());

        $em->clear();
        $repo = static::getContainer()->get(HealthMetricRepository::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'sleeper@test.local']);

        // Every night now reads ~6h (the average of the three normal nights).
        $nights = $repo->getSleepNightlyData(
            $user,
            new \DateTimeImmutable('2000-01-01'),
            new \DateTimeImmutable('tomorrow'),
        );
        self::assertCount(4, $nights);
        foreach ($nights as $night) {
            self::assertEqualsWithDelta(6.0, (float) $night['total_hours'], 0.01, $night['sleep_date']);
        }

        // The corrected night is a single sleep_asleep row tagged corrected-avg…
        $rows = $em->getRepository(HealthMetric::class)->findBy(['user' => $user]);
        $corrected = array_values(array_filter(
            $rows,
            static fn (HealthMetric $m): bool => $m->getSource() === 'corrected-avg',
        ));
        self::assertCount(1, $corrected);
        self::assertSame(HealthMetricType::SleepAsleep, $corrected[0]->getMetricType());
        self::assertEqualsWithDelta(360.0, $corrected[0]->getValue(), 0.01);

        // …and the original 700-minute in-bed segment is gone.
        $inBed = array_filter(
            $rows,
            static fn (HealthMetric $m): bool => $m->getMetricType() === HealthMetricType::SleepInBed,
        );
        self::assertCount(3, $inBed, 'the three normal in-bed nights survive; the outlier was replaced');
    }

    public function testDryRunWritesNothing(): void
    {
        $kernel = self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'sleeper@test.local');
        $this->seedSleepNight($em, $user, '2025-11-01', 360.0);
        $this->seedSleepNight($em, $user, '2025-11-05', 700.0);
        $em->flush();

        $tester = $this->runCmd('sleeper@test.local', ['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Dry run', $tester->getDisplay());

        $em->clear();
        $rows = $em->getRepository(HealthMetric::class)->findAll();
        self::assertCount(2, $rows, 'dry run leaves both seeded rows untouched');
        foreach ($rows as $row) {
            self::assertNotSame('corrected-avg', $row->getSource());
        }
    }

    public function testErrorsWhenEveryNightIsAboveThreshold(): void
    {
        $kernel = self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $user = $this->seedUser($em, 'sleeper@test.local');
        $this->seedSleepNight($em, $user, '2025-11-05', 700.0);
        $em->flush();

        $tester = $this->runCmd('sleeper@test.local');
        self::assertSame(1, $tester->getStatusCode(), 'no normal night to average → failure');
        self::assertStringContainsString('no "normal" night', $tester->getDisplay());
    }

    /** @param array<string, mixed> $opts */
    private function runCmd(string $email, array $opts = []): CommandTester
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:fix-sleep-outliers')
        );
        $tester->execute(['email' => $email] + $opts);

        return $tester;
    }

    private function seedSleepNight(EntityManagerInterface $em, User $user, string $date, float $minutes): void
    {
        // 23:00 local → DATE(recorded_at - 12h) buckets it on $date (the noon boundary).
        $metric = new HealthMetric();
        $metric->setUser($user)
            ->setMetricType(HealthMetricType::SleepInBed)
            ->setValue($minutes)
            ->setRecordedAt(new \DateTimeImmutable($date . ' 23:00:00'))
            ->setSource('test-seed');
        $em->persist($metric);
    }

    private function seedUser(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Seeded Sleeper');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setTimezone('America/New_York');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
