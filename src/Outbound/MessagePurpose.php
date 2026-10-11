<?php

declare(strict_types=1);

namespace App\Outbound;

/** Why a scheduled message is being sent (see CONTEXT.md: Digest, Check-in). */
enum MessagePurpose
{
    case Digest;
    case CheckIn;
}
