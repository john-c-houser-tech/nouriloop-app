<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Snapshot of how far a user has gotten connecting their CGM (Lingo/Stelo →
 * Health Auto Export → NouriLoop). Fully derived from existing tables by
 * CgmSetupStatusService — no wizard state is persisted anywhere.
 *
 * The state ladder, best first:
 *   dense        — recent glucose at per-reading cadence; setup works end to end
 *   sparse       — recent glucose arrived but too few/too far apart for meal
 *                  curves (the classic "Day aggregation" misconfiguration)
 *   waiting      — the phone reached the API (fresh token use) but no recent glucose
 *   token_unused — a token exists but was never used recently
 *   no_token     — nothing yet
 */
final class CgmSetupStatus
{
    public const STATE_NO_TOKEN     = 'no_token';
    public const STATE_TOKEN_UNUSED = 'token_unused';
    public const STATE_WAITING      = 'waiting';
    public const STATE_SPARSE       = 'sparse';
    public const STATE_DENSE        = 'dense';

    public function __construct(
        public readonly string $state,
        public readonly bool $tokenCreated,
        public readonly ?\DateTimeImmutable $phoneReachedAt,
        public readonly ?string $latestRecordedAt, // user-local wall clock, 'Y-m-d H:i'
        public readonly int $recentCount,
        public readonly int $recentDistinctTimestamps,
        public readonly ?int $medianGapMinutes,
    ) {
    }

    /**
     * @return array{state: string, tokenCreated: bool, phoneReachedAt: ?string,
     *               glucose: array{latestRecordedAt: ?string, recentCount: int,
     *                              recentDistinctTimestamps: int, medianGapMinutes: ?int}}
     */
    public function toArray(): array
    {
        return [
            'state'          => $this->state,
            'tokenCreated'   => $this->tokenCreated,
            'phoneReachedAt' => $this->phoneReachedAt?->format(\DateTimeInterface::ATOM),
            'glucose'        => [
                'latestRecordedAt'         => $this->latestRecordedAt,
                'recentCount'              => $this->recentCount,
                'recentDistinctTimestamps' => $this->recentDistinctTimestamps,
                'medianGapMinutes'         => $this->medianGapMinutes,
            ],
        ];
    }
}
