<?php

declare(strict_types=1);

namespace App\Service;

class HeicConverterService
{
    private const HEIC_EXTENSIONS = ['heic', 'heif'];

    public function isHeic(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return in_array($ext, self::HEIC_EXTENSIONS, true);
    }

    /**
     * Converts a HEIC/HEIF file to JPEG and removes the original.
     *
     * Tries each available backend in turn so the app works across environments:
     * macOS `sips` (local dev), libheif's `heif-convert`, the ImageMagick CLI, then
     * the Imagick PHP extension. Throws a user-friendly error if none can handle HEIC.
     */
    public function convertToJpeg(string $heicPath): string
    {
        $jpegPath = preg_replace('/\.(heic|heif)$/i', '.jpg', $heicPath);

        $backends = [
            'convertWithSips',
            'convertWithHeifConvert',
            'convertWithMagickCli',
            'convertWithImagickExtension',
        ];
        foreach ($backends as $backend) {
            if ($this->{$backend}($heicPath, $jpegPath)) {
                if ($heicPath !== $jpegPath && is_file($heicPath)) {
                    unlink($heicPath);
                }

                return $jpegPath;
            }
        }

        throw new \RuntimeException(
            'This server has no HEIC converter available (tried sips, heif-convert, '
            . 'ImageMagick, and the Imagick extension). Please upload a JPEG or PNG instead.'
        );
    }

    private function convertWithHeifConvert(string $in, string $out): bool
    {
        // heif-convert ships with libheif (apt package libheif-examples) and is the
        // most reliable HEIC→JPEG path on Linux.
        if (!$this->binaryExists('heif-convert')) {
            return false;
        }

        exec(
            sprintf('heif-convert %s %s 2>&1', escapeshellarg($in), escapeshellarg($out)),
            $output,
            $exitCode,
        );

        return $exitCode === 0 && is_file($out);
    }

    private function convertWithSips(string $in, string $out): bool
    {
        if (!$this->binaryExists('sips')) {
            return false;
        }

        exec(
            sprintf('sips -s format jpeg %s --out %s 2>&1', escapeshellarg($in), escapeshellarg($out)),
            $output,
            $exitCode,
        );

        return $exitCode === 0 && is_file($out);
    }

    private function convertWithMagickCli(string $in, string $out): bool
    {
        // ImageMagick 7 ships `magick`; 6 ships `convert`. Either needs a HEIC
        // delegate (libheif) compiled in, otherwise the command fails and we
        // fall through to the next backend.
        $bin = $this->binaryExists('magick') ? 'magick' : ($this->binaryExists('convert') ? 'convert' : null);
        if ($bin === null) {
            return false;
        }

        exec(
            sprintf('%s %s %s 2>&1', $bin, escapeshellarg($in), escapeshellarg($out)),
            $output,
            $exitCode,
        );

        return $exitCode === 0 && is_file($out);
    }

    private function convertWithImagickExtension(string $in, string $out): bool
    {
        if (!extension_loaded('imagick')) {
            return false;
        }

        try {
            $image = new \Imagick();
            $image->readImage($in);
            $image->setImageFormat('jpeg');
            $image->writeImage($out);
            $image->clear();
        } catch (\Throwable) {
            return false;
        }

        return is_file($out);
    }

    private function binaryExists(string $bin): bool
    {
        exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($bin)), $output, $exitCode);

        return $exitCode === 0;
    }
}
