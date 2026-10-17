<?php

declare(strict_types=1);

namespace App\Ai;

use App\Service\ApiImageEncoder;

/**
 * The photo half of {@see AiClient::completeWithImages()}, shared by every provider so
 * they agree on which files are sent and when a call is refused: blank, missing and
 * unreadable paths are dropped, and if paths were given and none remain it throws
 * before any request (nothing is charged). Each image comes back as encoded bytes +
 * MIME type, oversized photos downscaled for the request only.
 */
final class ImageInputs
{
    /**
     * @param list<string> $imagePaths
     *
     * @return list<array{bytes: string, mime: string}>
     *
     * @throws \RuntimeException when $imagePaths is non-empty but none can be read
     */
    public static function load(array $imagePaths): array
    {
        $paths = array_values(array_filter(
            $imagePaths,
            // is_readable too: a file that exists but can't be read would otherwise
            // make the encoder throw and sink the readable photos sent with it.
            static fn(string $p): bool => $p !== '' && is_file($p) && is_readable($p),
        ));
        if ($imagePaths !== [] && $paths === []) {
            throw new \RuntimeException('No readable image to analyze.');
        }

        $images = [];
        foreach ($paths as $path) {
            [$bytes, $mime] = ApiImageEncoder::encode($path);
            $images[] = ['bytes' => $bytes, 'mime' => $mime];
        }

        return $images;
    }
}
