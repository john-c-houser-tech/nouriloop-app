<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\AllowanceKind;

/**
 * Thrown when a user tries an AI analysis after using up their monthly allowance
 * (AnalysisAllowance). Every analysis controller catches it, flashes
 * {@see messageKey()} and redirects to {@see redirectRoute()}: the pricing page for a
 * Free user, Settings ▸ AI provider for a paid one (where their own key lifts the cap).
 */
class PhotoLimitExceededException extends \RuntimeException
{
    public function __construct(
        public readonly AllowanceKind $kind,
        public readonly int $limit,
    ) {
        parent::__construct(sprintf('Monthly allowance of %d AI analyses reached (%s).', $limit, $kind->value));
    }

    public function messageKey(): string
    {
        return $this->kind->limitReachedKey();
    }

    /** @return array{limit: int} */
    public function messageParameters(): array
    {
        return ['limit' => $this->limit];
    }

    public function redirectRoute(): string
    {
        return $this->kind->route();
    }
}
