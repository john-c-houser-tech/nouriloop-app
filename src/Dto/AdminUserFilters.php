<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\SubscriptionTier;

/**
 * The set of filters the admin user list can narrow by, shared verbatim between
 * UserRepository::searchPage() (one page) and countSearch() (the matching total) so
 * the two can never disagree. `now`/`activeCutoff` are carried here (not derived in
 * the repository) so the same instant that drives the page's badges also drives the
 * grace-period / comp-expiry / activity SQL — no drift between what's shown and what's
 * counted.
 *
 * `tier` filters on the *effective* tier (SubscriptionService::effectiveTier), not the
 * raw subscription_tier column: a grandfathered beta tester is stored Free but comped
 * Loop+, so a raw-column filter would put them under the wrong heading. `activity`
 * mirrors the list's "logged in AND logged a meal in the last 7 days" definition.
 */
final class AdminUserFilters
{
    public const ACTIVITY_ACTIVE = 'active';
    public const ACTIVITY_INACTIVE = 'inactive';
    public const ACTIVITIES = [self::ACTIVITY_ACTIVE, self::ACTIVITY_INACTIVE];

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_PENDING_DELETION = 'pending_deletion';
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUSES = [
        self::STATUS_ENABLED,
        self::STATUS_DISABLED,
        self::STATUS_PENDING_DELETION,
        self::STATUS_UNVERIFIED,
    ];

    public function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly \DateTimeImmutable $activeCutoff,
        public readonly ?string $q = null,
        public readonly ?SubscriptionTier $tier = null,
        public readonly ?string $activity = null,
        public readonly ?string $status = null,
    ) {
    }
}
