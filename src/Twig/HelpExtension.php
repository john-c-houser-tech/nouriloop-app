<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `help_link('topic', 'anchor')` — a small "?" icon linking to the matching help
 * page, dropped into a page header. A Twig function (not a macro) so it's available
 * in every template with no per-file import.
 */
class HelpExtension extends AbstractExtension
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('help_link', $this->helpLink(...), ['is_safe' => ['html']]),
        ];
    }

    public function helpLink(string $topic, string $anchor = ''): string
    {
        $url = $this->urlGenerator->generate('app_help_show', ['topic' => $topic]);
        if ($anchor !== '') {
            $url .= '#' . rawurlencode($anchor);
        }

        return sprintf(
            '<a href="%s" class="text-muted ms-1 fs-6 text-decoration-none" title="Help"'
            . ' aria-label="Help for this page"><i class="bi bi-question-circle" aria-hidden="true"></i></a>',
            htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
