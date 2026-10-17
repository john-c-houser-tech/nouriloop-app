<?php

declare(strict_types=1);

namespace App\Enum;

/** What AllowanceTopUpService::credit() did with a Checkout session. */
enum TopUpCredit
{
    /** This call wrote the top-up. */
    case Credited;
    /** A valid, paid top-up that was already credited (usually by the webhook first). */
    case AlreadyCredited;
    /** Not a paid top-up for this user — nothing to credit (yet). */
    case NotCredited;

    /** Whether the user has the analyses now. */
    public function isApplied(): bool
    {
        return $this !== self::NotCredited;
    }
}
