<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The natural-language help assistant's reply: a plain-prose answer plus 1–3
 * recommended help pages (each `{title, url, reason}`). Built by
 * {@see \App\Service\HelpAssistantService} from Claude's JSON response, with a
 * deterministic fallback when the model is unavailable.
 */
final class HelpAnswer
{
    /**
     * @param list<array{title: string, url: string, reason: string}> $pages
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $pages = [],
    ) {
    }
}
