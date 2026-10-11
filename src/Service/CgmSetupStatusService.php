<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CgmSetupStatus;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\HealthMetricRepository;

/**
 * Derives the CGM setup wizard's verification ladder from existing tables —
 * no wizard state is stored. The rungs, weakest to strongest evidence:
 *
 *   1. an ApiToken exists;
 *   2. one was used within the recency window (ApiTokenHandler stamps
 *      lastUsedAt on every successful bearer auth) — "your phone reached us";
 *   3. blood-glucose rows arrived within the window;
 *   4. those rows are dense enough for postprandial curves.
 *
 * Glucose evidence trumps the token rungs (a file-upload user still reaches
 * dense/sparse with no token at all). Everything is judged against a recency
 * window, so months-old test data can't fake a working setup.
 *
 * Density: PostprandialResponseService needs MIN_READINGS_FOR_CURVE (6)
 * readings inside 180 minutes, i.e. ≤ ~30-minute cadence. A real Lingo/Stelo
 * feed samples ~every 5 minutes; Health Auto Export's "Day" aggregation
 * collapses it to one point per day, which imports fine but breaks curves.
 * The median gap of recent readings separates the two cleanly.
 */
final class CgmSetupStatusService
{
    public const RECENT_WINDOW_HOURS = 48;

    /** ≥ this many distinct recent timestamps required for "dense" (~1 h of 5-min cadence). */
    public const DENSE_MIN_READINGS = 12;

    /** Median gap between recent readings must be ≤ this many minutes for "dense". */
    public const DENSE_MAX_MEDIAN_GAP_MIN = 20;

    /** Cap on rows fetched for the density check — 48 h at 5-min cadence is ~576, 300 is plenty. */
    private const TIMESTAMP_FETCH_LIMIT = 300;

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly HealthMetricRepository $metrics,
        private readonly UserTime $userTime,
    ) {
    }

    public function check(User $user): CgmSetupStatus
    {
        // Token rungs. lastUsedAt is a server-frame instant (see Timezones in
        // CLAUDE.md), so freshness is judged against server "now".
        $tokenCreated   = false;
        $phoneReachedAt = null;
        $serverNow      = new \DateTimeImmutable();
        $windowSeconds  = self::RECENT_WINDOW_HOURS * 3600;

        foreach ($this->tokens->findByUserOrdered($user) as $token) {
            $tokenCreated = true;
            $usedAt       = $token->getLastUsedAt();
            if (
                $usedAt !== null
                && $serverNow->getTimestamp() - $usedAt->getTimestamp() <= $windowSeconds
                && ($phoneReachedAt === null || $usedAt > $phoneReachedAt)
            ) {
                $phoneReachedAt = $usedAt;
            }
        }

        // Glucose rungs. recorded_at is the user's local wall clock, so the
        // window boundary must be built in the user's zone (UserTime).
        $from       = $this->userTime->now($user)->modify(sprintf('-%d hours', self::RECENT_WINDOW_HOURS));
        $timestamps = $this->metrics->findRecentGlucoseTimestamps($user, $from, self::TIMESTAMP_FETCH_LIMIT);

        $distinct  = array_values(array_unique($timestamps));
        $medianGap = $this->medianGapMinutes($distinct);
        $isDense   = count($distinct) >= self::DENSE_MIN_READINGS
            && $medianGap !== null
            && $medianGap <= self::DENSE_MAX_MEDIAN_GAP_MIN;

        $state = match (true) {
            $isDense                 => CgmSetupStatus::STATE_DENSE,
            $timestamps !== []       => CgmSetupStatus::STATE_SPARSE,
            $phoneReachedAt !== null => CgmSetupStatus::STATE_WAITING,
            $tokenCreated            => CgmSetupStatus::STATE_TOKEN_UNUSED,
            default                  => CgmSetupStatus::STATE_NO_TOKEN,
        };

        return new CgmSetupStatus(
            $state,
            $tokenCreated,
            $phoneReachedAt,
            $timestamps === [] ? null : substr($timestamps[0], 0, 16),
            count($timestamps),
            count($distinct),
            $medianGap,
        );
    }

    /**
     * Median gap in minutes between consecutive distinct timestamps (newest
     * first, as fetched), or null with fewer than two. Wall-clock strings are
     * compared as plain deltas — offsets cancel within one user's frame.
     *
     * The median (not mean/max) is deliberate: wall-clock storage inherently
     * shows one ~65-minute gap at a DST spring-forward (the skipped hour),
     * and strtotime()'s server-zone parse can distort a couple of readings
     * around the server's own transition — single outliers like these can't
     * move the median of a ~300-reading window, so a dense feed stays dense.
     *
     * @param list<string> $distinct 'Y-m-d H:i:s' values, newest first
     */
    private function medianGapMinutes(array $distinct): ?int
    {
        if (count($distinct) < 2) {
            return null;
        }

        $seconds = array_map(strtotime(...), $distinct);
        $gaps    = [];
        for ($i = 1, $n = count($seconds); $i < $n; $i++) {
            $gaps[] = $seconds[$i - 1] - $seconds[$i];
        }
        sort($gaps);

        $mid    = intdiv(count($gaps), 2);
        $median = count($gaps) % 2 === 1
            ? $gaps[$mid]
            : intdiv($gaps[$mid - 1] + $gaps[$mid], 2);

        return (int) round($median / 60);
    }
}
