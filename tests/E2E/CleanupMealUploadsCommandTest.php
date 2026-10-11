<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Command\CleanupMealUploadsCommand;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Enum\MealType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The abandoned-upload sweeper (in-process, real DB): old files with no
 * meal_entry reference are deleted; referenced or recent files survive;
 * dry-run touches nothing.
 *
 * ⚠️ The command is constructed by hand with a per-test scratch directory —
 * NEVER with the container's `%uploads_dir%`: that parameter points at the
 * real, dev-shared public/uploads/meals (only the DB name gets a `_test`
 * suffix), so running the sweeper there deletes the dev environment's own
 * unreferenced files. (Learned the hard way — the first version of this test
 * swept a real dev file.)
 */
final class CleanupMealUploadsCommandTest extends KernelTestCase
{
    use DbResetTrait;

    private string $scratchDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratchDir = sys_get_temp_dir() . '/cleanup-meals-' . bin2hex(random_bytes(6));
        mkdir($this->scratchDir, 0775, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->scratchDir, 0775); // undo the unlink-failure test's lock
        foreach (glob($this->scratchDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratchDir);
        parent::tearDown();
    }

    public function testSweepsOnlyOldUnreferencedFiles(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        // An old photo referenced by a saved meal — must survive.
        $referenced = $this->plantFile('cleanup-referenced.jpg', ageHours: 100);
        $this->seedMealWith($em, 'uploads/meals/' . basename($referenced));
        // An old orphan (abandoned session) — must be deleted.
        $oldOrphan = $this->plantFile('cleanup-old-orphan.jpg', ageHours: 100);
        // A fresh orphan (possibly a live session) — must survive.
        $freshOrphan = $this->plantFile('cleanup-fresh-orphan.jpg', ageHours: 1);

        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertFileExists($referenced);
        self::assertFileDoesNotExist($oldOrphan);
        self::assertFileExists($freshOrphan);
        self::assertStringContainsString('Deleted 1 abandoned photo(s)', $tester->getDisplay());
    }

    public function testDryRunDeletesNothing(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $oldOrphan = $this->plantFile('cleanup-dryrun-orphan.jpg', ageHours: 100);

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertFileExists($oldOrphan, 'dry-run must not delete');
        self::assertStringContainsString('Would delete 1 abandoned photo(s)', $tester->getDisplay());
    }

    public function testUndeletableFileFailsTheRunInsteadOfCountingAsDeleted(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateAll($em);

        $oldOrphan = $this->plantFile('cleanup-locked-orphan.jpg', ageHours: 100);
        // A read-only parent directory makes unlink() fail (as an ACL
        // regression on the server would).
        chmod($this->scratchDir, 0555);

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([]);
        } finally {
            chmod($this->scratchDir, 0775);
        }

        self::assertSame(1, $exitCode, 'a failed deletion must fail the run');
        self::assertFileExists($oldOrphan);
        self::assertStringContainsString('could not be removed', $tester->getDisplay());
        self::assertStringNotContainsString('Deleted 1 abandoned', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $command = new CleanupMealUploadsCommand(
            static::getContainer()->get(Connection::class),
            $this->scratchDir,
        );

        return new CommandTester($command);
    }

    private function plantFile(string $name, int $ageHours): string
    {
        $path = $this->scratchDir . '/' . $name;
        file_put_contents($path, 'jpeg-bytes');
        touch($path, time() - $ageHours * 3600);

        return $path;
    }

    private function seedMealWith(EntityManagerInterface $em, string $webPath): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('cleanup@test.local');
        $user->setDisplayName('Cleanup Tester');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);

        $meal = new MealEntry();
        $meal->setUser($user);
        $meal->setMealType(MealType::Lunch);
        $meal->setImagePath($webPath);
        $meal->setLoggedAt(new \DateTimeImmutable('-5 days'));
        $em->persist($meal);

        $em->flush();
    }
}
