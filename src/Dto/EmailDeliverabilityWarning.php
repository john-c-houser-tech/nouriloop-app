<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A soft "this address probably can't receive mail" signal — never a hard
 * validation error. `typo` = the domain is on the known-misspelling list
 * (suggestedEmail carries the one-click correction); `no_mx` = DNS found no
 * mail server for the domain.
 */
final class EmailDeliverabilityWarning
{
    public function __construct(
        public readonly string $type, // 'typo' | 'no_mx'
        public readonly string $domain,
        public readonly ?string $suggestedEmail = null,
    ) {
    }
}
