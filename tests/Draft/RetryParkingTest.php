<?php

declare(strict_types=1);

namespace App\Tests\Draft;

use App\Draft\RetryParking;
use PHPUnit\Framework\TestCase;

final class RetryParkingTest extends TestCase
{
    /** @var list<string> */
    private array $photos = [];

    protected function setUp(): void
    {
        foreach (['a', 'b'] as $name) {
            $path = tempnam(sys_get_temp_dir(), 'parked-' . $name);
            $this->photos[] = $path;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->photos as $path) {
            @unlink($path);
        }
    }

    public function testAParkedRetryRoundTripsUnderItsPreTypedKeys(): void
    {
        $parked = new RetryParking($this->photos, 'uploads/meals/meal-a.jpg', 'lunch', 'from Sweetgreen', '');

        self::assertSame(
            ['image_paths', 'image_web_path', 'meal_type', 'notes', 'describe'],
            array_keys($parked->toArray()),
        );
        self::assertEquals($parked, RetryParking::fromArray($parked->toArray()));
        self::assertSame($this->photos, $parked->stagedPaths());
    }

    public function testAParkingWhosePhotoVanishedIsStale(): void
    {
        $data = (new RetryParking($this->photos, 'uploads/meals/meal-a.jpg', 'lunch', null, ''))->toArray();
        unlink($this->photos[1]);

        self::assertNull(RetryParking::fromArray($data));
    }

    public function testNothingParkedIsNotAParking(): void
    {
        self::assertNull(RetryParking::fromArray(['image_paths' => [], 'image_web_path' => 'x']));
    }
}
