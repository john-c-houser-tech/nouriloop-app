<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AllowanceKind;

/**
 * A user's monthly AI-analysis allowance right now, for the counters on the pages that
 * spend it. Only users who have one get a status (see AnalysisAllowance::status()).
 */
final readonly class AllowanceStatus
{
    public function __construct(
        public AllowanceKind $kind,
        public int $limit,
        public int $remaining,
    ) {
    }

    /** At least 80% used: the counter turns amber and shows its link (decision Q3). */
    public function isLow(): bool
    {
        return $this->remaining * 5 <= $this->limit;
    }
}
