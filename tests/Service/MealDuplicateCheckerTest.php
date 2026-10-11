<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Repository\MealEntryRepository;
use App\Service\MealDuplicateChecker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MealDuplicateCheckerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mealdup-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /** Writes an image file and returns the web path the app would store. */
    private function image(string $name, string $bytes): string
    {
        file_put_contents($this->dir . '/' . $name, $bytes);

        return 'uploads/meals/' . $name;
    }

    /** @param list<MealEntry> $existing */
    private function checker(array $existing, ?LoggerInterface $logger = null): MealDuplicateChecker
    {
        $repo = $this->createStub(MealEntryRepository::class);
        $repo->method('findBy')->willReturn($existing);

        return new MealDuplicateChecker($repo, $logger ?? new NullLogger(), $this->dir);
    }

    public function testSamePhotoAndTimeIsDuplicateAndLogged(): void
    {
        // Same bytes, different filename (each save copies to a unique name).
        $newPath = $this->image('new.jpg', 'IDENTICAL-PHOTO-BYTES');
        $existing = (new MealEntry())->setImagePath($this->image('old.jpg', 'IDENTICAL-PHOTO-BYTES'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $user = (new User())->setEmail('dup@test.local');
        $result = $this->checker([$existing], $logger)
            ->isDuplicate($user, new \DateTimeImmutable('2026-06-19 19:25:00'), $newPath);

        self::assertTrue($result);
    }

    public function testDifferentPhotoIsNotDuplicate(): void
    {
        $newPath = $this->image('new.jpg', 'PHOTO-A');
        $existing = (new MealEntry())->setImagePath($this->image('old.jpg', 'PHOTO-B'));
        $when = new \DateTimeImmutable('2026-06-19 19:25:00');

        self::assertFalse($this->checker([$existing])->isDuplicate(new User(), $when, $newPath));
    }

    public function testNoMealAtThatTimeIsNotDuplicate(): void
    {
        $newPath = $this->image('new.jpg', 'PHOTO-A');

        self::assertFalse(
            $this->checker([])->isDuplicate(new User(), new \DateTimeImmutable('2026-06-19 19:25:00'), $newPath)
        );
    }

    public function testNullImagePathIsNotDuplicate(): void
    {
        self::assertFalse(
            $this->checker([])->isDuplicate(new User(), new \DateTimeImmutable(), null)
        );
    }
}
