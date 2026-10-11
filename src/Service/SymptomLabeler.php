<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SymptomType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one place a SymptomType's display name is resolved (i18n). Built-ins
 * translate via their stable `typeKey` (`symptom.builtin.<key>`; the seeded
 * English label is only a missing-key fallback). Custom symptoms are user data
 * and render verbatim. Twig gets this via `symptom_label()`
 * (SymptomLabelExtension); controllers/services that put symptom names into
 * view data or JS payloads must use it too.
 */
class SymptomLabeler
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function label(SymptomType $type): string
    {
        if (!$type->isBuiltin()) {
            return $type->getLabel();
        }

        $key = 'symptom.builtin.' . $type->getTypeKey();
        $translated = $this->translator->trans($key);

        return $translated === $key ? $type->getLabel() : $translated;
    }
}
