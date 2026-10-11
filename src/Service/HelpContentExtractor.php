<?php

declare(strict_types=1);

namespace App\Service;

use Twig\Environment;

/**
 * Pulls the plain-text body of a help topic out of its Twig template, so the
 * natural-language assistant ({@see HelpAssistantService}) can ground Claude in the
 * exact same content users read — no second copy of the help text to keep in sync.
 *
 * Renders just the `help_body` block of `templates/help/<slug>.html.twig` and strips
 * the markup (the leading screenshot `<figure>` falls away with its tag). Memoized
 * per request; the bodies only change on deploy.
 */
class HelpContentExtractor
{
    /** @var array<string, string> */
    private array $memo = [];

    public function __construct(private readonly Environment $twig)
    {
    }

    /** Plain-text body of one help topic (empty string if the template can't be rendered). */
    public function bodyText(string $slug): string
    {
        return $this->memo[$slug] ??= $this->extract($slug);
    }

    private function extract(string $slug): string
    {
        try {
            $html = $this->twig->load('help/' . $slug . '.html.twig')->renderBlock('help_body');
        } catch (\Throwable) {
            return '';
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
