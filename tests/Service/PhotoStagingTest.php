<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\StagingPurpose;
use App\Exception\PhotoStagingFailed;
use App\Service\HeicConverterService;
use App\Service\PhotoStaging;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Validation;

final class PhotoStagingTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/photo-staging-' . bin2hex(random_bytes(4));
        mkdir($this->publicDir . '/uploads/meals', 0777, true);
        mkdir($this->publicDir . '/uploads/supplements', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->publicDir));
    }

    public function testStagesEachFileUnderAnUnguessableNameWithItsPurposePrefix(): void
    {
        $staged = $this->staging()->stage(
            [$this->jpegUpload(), $this->jpegUpload()],
            StagingPurpose::MealReanalysis,
        );

        self::assertCount(2, $staged);
        foreach ($staged as $photo) {
            self::assertFileExists($photo->path);
            self::assertMatchesRegularExpression(
                '#^uploads/meals/meal-reanalysis-[0-9a-f]{32}\.jpg$#',
                $photo->webPath,
            );
            self::assertSame($this->publicDir . '/' . $photo->webPath, $photo->path);
        }
        self::assertNotSame($staged[0]->path, $staged[1]->path);
    }

    public function testEveryPurposeStagesIntoTheSweptDirectory(): void
    {
        $staged = $this->staging()->stage([$this->jpegUpload()], StagingPurpose::Supplement);

        self::assertStringStartsWith('uploads/meals/supp-', $staged[0]->webPath);
    }

    public function testKeepMovesAStagedPhotoOutOfTheStagingArea(): void
    {
        $staging = $this->staging();
        $photo = $staging->stage([$this->jpegUpload()], StagingPurpose::Supplement)[0];

        $kept = $staging->keep($photo->path, 'uploads/supplements');

        self::assertSame('uploads/supplements/' . basename($photo->path), $kept);
        self::assertFileExists($this->publicDir . '/' . $kept);
        self::assertFileDoesNotExist($photo->path);
    }

    public function testKeepingAPhotoThatWasAlreadySweptKeepsNothing(): void
    {
        self::assertNull($this->staging()->keep($this->publicDir . '/uploads/meals/gone.jpg', 'uploads/supplements'));
    }

    public function testAnInvalidFileRollsBackTheWholeBatch(): void
    {
        $notAnImage = $this->upload('just text', 'notes.txt', 'text/plain');

        try {
            $this->staging()->stage([$this->jpegUpload(), $notAnImage], StagingPurpose::MealPhoto);
            self::fail('Expected staging to fail.');
        } catch (PhotoStagingFailed $e) {
            self::assertSame('Please upload a valid image (JPEG, PNG, WebP, or HEIC).', $e->invalidFileMessage());
        }

        self::assertSame([], $this->filesIn('uploads/meals'));
    }

    public function testAFailedHeicConversionRollsBackTheWholeBatch(): void
    {
        $converter = new class extends HeicConverterService {
            public function isHeic(string $filePath): bool
            {
                return true;
            }

            public function convertToJpeg(string $heicPath): string
            {
                throw new \RuntimeException('no converter');
            }
        };

        try {
            $this->staging($converter)->stage([$this->jpegUpload(), $this->jpegUpload()], StagingPurpose::Recipe);
            self::fail('Expected staging to fail.');
        } catch (PhotoStagingFailed $e) {
            self::assertStringContainsString('HEIC conversion failed', $e->getMessage());
            self::assertNull($e->invalidFileMessage(), 'a conversion failure is not a rejected file');
        }

        self::assertSame([], $this->filesIn('uploads/meals'));
    }

    public function testAConvertedHeicIsStagedUnderItsJpegName(): void
    {
        $converter = new class extends HeicConverterService {
            public function isHeic(string $filePath): bool
            {
                return true;
            }

            public function convertToJpeg(string $heicPath): string
            {
                $jpeg = preg_replace('/\.[a-z]+$/', '', $heicPath) . '-converted.jpg';
                rename($heicPath, $jpeg);

                return $jpeg;
            }
        };

        $staged = $this->staging($converter)->stage([$this->jpegUpload()], StagingPurpose::MealPhoto);

        self::assertStringEndsWith('-converted.jpg', $staged[0]->webPath);
        self::assertFileExists($staged[0]->path);
    }

    public function testDiscardRemovesPhotosAndPathsAndIgnoresMissingOnes(): void
    {
        $staging = $this->staging();
        [$a, $b] = $staging->stage([$this->jpegUpload(), $this->jpegUpload()], StagingPurpose::MealPhoto);

        $staging->discard([$a, $b->path, '/no/such/file.jpg', null, '']);

        self::assertFileDoesNotExist($a->path);
        self::assertFileDoesNotExist($b->path);
    }

    public function testWebPathAndPathAreInverse(): void
    {
        $staging = $this->staging();
        $path = $this->publicDir . '/uploads/meals/meal-abc.jpg';

        self::assertSame('uploads/meals/meal-abc.jpg', $staging->webPath($path));
        self::assertSame($path, $staging->path('uploads/meals/meal-abc.jpg'));
    }

    public function testWebPathRefusesAFileOutsidePublic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->staging()->webPath('/etc/passwd');
    }

    private function staging(?HeicConverterService $converter = null): PhotoStaging
    {
        return new PhotoStaging(
            $this->publicDir,
            $converter ?? new HeicConverterService(),
            new AsciiSlugger(),
            Validation::createValidator(),
        );
    }

    private function jpegUpload(): UploadedFile
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($im);

        return $this->upload((string) ob_get_clean(), 'photo.jpg', 'image/jpeg');
    }

    private function upload(string $bytes, string $name, string $mime): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, $name, $mime, null, true);
    }

    /** @return list<string> */
    private function filesIn(string $webDir): array
    {
        return array_values(array_diff((array) scandir($this->publicDir . '/' . $webDir), ['.', '..']));
    }
}
