<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * An uploaded photo held for a draft or re-analysis proposal, not yet part of
 * any saved record (see CONTEXT.md). $path is the file on disk, $webPath what
 * the page renders.
 */
final readonly class StagedPhoto
{
    public function __construct(
        public string $path,
        public string $webPath,
    ) {
    }
}
