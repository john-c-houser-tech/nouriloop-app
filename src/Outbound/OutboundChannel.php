<?php

declare(strict_types=1);

namespace App\Outbound;

/**
 * A channel a scheduled message can leave by. In-app is not one: the
 * notification center always receives, so it never needs a reachability check.
 */
enum OutboundChannel
{
    case Email;
    case Sms;
}
