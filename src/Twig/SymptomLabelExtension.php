<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\SymptomType;
use App\Service\SymptomLabeler;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `symptom_label(type)` — the one way to render a SymptomType's name in Twig.
 * Delegates to App\Service\SymptomLabeler (shared with controllers that put
 * symptom names into view data / JS payloads); see that class for the rules.
 */
class SymptomLabelExtension extends AbstractExtension
{
    public function __construct(private readonly SymptomLabeler $labeler)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('symptom_label', $this->labeler->label(...)),
        ];
    }
}
