<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\StagedPhoto;
use App\Enum\StagingPurpose;
use App\Exception\PhotoStagingFailed;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Staged photos: the one place uploads for a draft or re-analysis proposal are
 * validated, written to disk under an unguessable name, HEIC-converted, and
 * later discarded. Every photo flow (meal log, retry, clarify, re-analysis,
 * recipe import, supplement scan) goes through it.
 *
 * Names are random (bin2hex(random_bytes(16))) because public/uploads is
 * web-served without auth: the name's entropy is the only thing keeping a
 * stranger from fetching someone's photos.
 */
final class PhotoStaging
{
    public const MAX_BYTES = '20M';
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private readonly string $publicDir,
        private readonly HeicConverterService $heicConverter,
        private readonly SluggerInterface $slugger,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Stages a batch, all or nothing: if any file is invalid or fails HEIC
     * conversion, the files already written for this batch are removed and it
     * throws. Any other failure (e.g. the directory isn't writable) is
     * rethrown as-is after the same rollback.
     *
     * @param list<UploadedFile> $files
     *
     * @return list<StagedPhoto>
     *
     * @throws PhotoStagingFailed
     */
    public function stage(array $files, StagingPurpose $purpose): array
    {
        $staged = [];
        foreach ($files as $file) {
            try {
                $staged[] = $this->stageOne($file, $purpose);
            } catch (\Throwable $e) {
                $this->discard($staged);
                throw $e;
            }
        }

        return $staged;
    }

    /**
     * Removes staged photos from disk. Accepts the photos themselves or their
     * on-disk paths (drafts still carry paths in the session); missing files
     * are ignored.
     *
     * @param iterable<StagedPhoto|string|null> $photos
     */
    public function discard(iterable $photos): void
    {
        foreach ($photos as $photo) {
            $path = $photo instanceof StagedPhoto ? $photo->path : $photo;
            if (is_string($path) && $path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Moves a staged photo out of the staging area into a permanent directory
     * under public/ (e.g. a saved supplement's thumbnail), where the cleanup
     * sweep no longer reaches it. Returns its new web path, or null when the
     * staged file is gone (swept while the draft sat open).
     */
    public function keep(string $path, string $webDir): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $dir = $this->path($webDir);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $target = $dir . '/' . basename($path);
        if (!rename($path, $target)) {
            throw new \RuntimeException('Could not move staged photo to ' . $webDir);
        }

        return $this->webPath($target);
    }

    /** The web path (relative to public/) for a staged file's on-disk path. */
    public function webPath(string $path): string
    {
        $prefix = rtrim($this->publicDir, '/') . '/';
        if (!str_starts_with($path, $prefix)) {
            throw new \InvalidArgumentException('Not a file under public/: ' . $path);
        }

        return substr($path, strlen($prefix));
    }

    /** The on-disk path for a web path (relative to public/). */
    public function path(string $webPath): string
    {
        return rtrim($this->publicDir, '/') . '/' . ltrim($webPath, '/');
    }

    private function stageOne(UploadedFile $file, StagingPurpose $purpose): StagedPhoto
    {
        $violations = $this->validator->validate($file, new File(
            maxSize: self::MAX_BYTES,
            mimeTypes: self::MIME_TYPES,
            mimeTypesMessage: 'Please upload a valid image (JPEG, PNG, WebP, or HEIC).',
        ));
        if (count($violations) > 0) {
            throw PhotoStagingFailed::invalidFile((string) $violations[0]->getMessage());
        }

        $dir = $this->path($purpose->webDir());
        $ext = $file->guessExtension() ?? 'jpg';
        $filename = $this->slugger->slug($purpose->prefix() . bin2hex(random_bytes(16))) . '.' . $ext;
        $file->move($dir, $filename);
        $path = $dir . '/' . $filename;

        if ($this->heicConverter->isHeic($path)) {
            try {
                $path = $this->heicConverter->convertToJpeg($path);
            } catch (\Throwable $e) {
                $this->discard([$path]);
                throw PhotoStagingFailed::conversionFailed($e);
            }
        }

        return new StagedPhoto($path, $this->webPath($path));
    }
}
