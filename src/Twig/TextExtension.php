<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TextExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('inline_md', $this->inlineMd(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Renders short, AI-generated prose (e.g. a goal check-in narrative) safely as
     * HTML. Escapes everything first — so the model can't inject markup — then turns
     * the only Markdown we expect in a coaching note, **bold** → <strong>, and
     * newlines → <br>. Defensive: the narrative prompt already asks for plain prose;
     * this guarantees a slip never shows raw `**` to the user (the bug it fixes).
     */
    public function inlineMd(?string $text): string
    {
        $escaped = htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $bolded  = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped) ?? $escaped;

        return nl2br($bolded, false);
    }
}
