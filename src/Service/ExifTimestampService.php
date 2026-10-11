<?php

declare(strict_types=1);

namespace App\Service;

class ExifTimestampService
{
    // EXIF DateTimeOriginal format: "YYYY:MM:DD HH:MM:SS"
    private const EXIF_DATE_FORMAT = 'Y:m:d H:i:s';

    /**
     * Reads the DateTimeOriginal field from a JPEG's EXIF data.
     * Returns null if EXIF is unavailable or the date cannot be parsed.
     */
    public function extractTimestamp(string $imagePath): ?\DateTimeImmutable
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'tiff', 'tif'], true)) {
            return null;
        }

        try {
            $exif = @exif_read_data($imagePath, 'EXIF');
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($exif)) {
            return null;
        }

        $raw = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
        if ($raw === null || $raw === '0000:00:00 00:00:00') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat(self::EXIF_DATE_FORMAT, $raw);

        return $dt !== false ? $dt : null;
    }
}
