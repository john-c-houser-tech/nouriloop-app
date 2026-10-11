<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Bytes + MIME for one image content block in a Claude vision request, shared by
 * the meal, recipe, and supplement analyzers.
 *
 * Per-image payload guardrails: the Messages API caps the whole request at
 * ~32 MB, and the upload forms legitimately accept several 20 MB photos — base64
 * alone would blow past the cap. Images above the byte threshold are re-encoded
 * (and, above the edge cap, downscaled) in-memory for the API request only; the
 * stored original is untouched. Also trims vision token cost — Claude gains
 * nothing from a >2000 px photo. Undecodable images (or ones that don't actually
 * shrink) are sent as-is.
 */
final class ApiImageEncoder
{
    public const MAX_BYTES = 4_000_000;
    public const MAX_EDGE = 2000;
    public const JPEG_QUALITY = 85;

    /** @return array{0: string, 1: string} [bytes, mime] */
    public static function encode(string $path): array
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException('Could not read image: ' . $path);
        }
        if (strlen($data) <= self::MAX_BYTES) {
            return [$data, self::mimeType($path)];
        }

        $im = @imagecreatefromstring($data);
        if ($im === false) {
            return [$data, self::mimeType($path)];
        }

        $width = imagesx($im);
        $height = imagesy($im);
        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        if ($scale < 1.0) {
            $scaled = imagescale($im, (int) round($width * $scale));
            if ($scaled !== false) {
                $im = $scaled;
            }
        }

        ob_start();
        imagejpeg($im, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();
        if ($jpeg === '' || strlen($jpeg) >= strlen($data)) {
            return [$data, self::mimeType($path)];
        }

        return [$jpeg, 'image/jpeg'];
    }

    private static function mimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png'   => 'image/png',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
