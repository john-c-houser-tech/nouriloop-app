<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * An analysis result that knows what its Claude call cost, so the allowance can
 * book that figure on the ledger.
 */
interface TokenUsage
{
    public function totalTokens(): int;
}
