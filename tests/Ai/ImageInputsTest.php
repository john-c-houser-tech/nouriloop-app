<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\ImageInputs;
use PHPUnit\Framework\TestCase;

final class ImageInputsTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @chmod($path, 0o600);
            @unlink($path);
        }
    }

    public function testAnUnreadableFileIsDroppedWithoutSinkingTheReadableOnes(): void
    {
        $readable = $this->tempImage();
        $unreadable = $this->unreadableImage();

        $images = ImageInputs::load([$unreadable, $readable]);

        self::assertCount(1, $images);
        self::assertSame((string) file_get_contents($readable), $images[0]['bytes']);
    }

    public function testOnlyUnreadableFilesAreRefused(): void
    {
        $unreadable = $this->unreadableImage();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No readable image to analyze.');
        ImageInputs::load([$unreadable, '', '/no/such/file.jpg']);
    }

    public function testNoPathsIsATextOnlyCall(): void
    {
        self::assertSame([], ImageInputs::load([]));
    }

    private function tempImage(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'img') . '.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        ) ?: '');
        $this->tempFiles[] = $path;

        return $path;
    }

    private function unreadableImage(): string
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can read a 0000 file, so unreadability cannot be staged.');
        }
        $path = $this->tempImage();
        chmod($path, 0o000);
        clearstatcache();

        return $path;
    }
}
