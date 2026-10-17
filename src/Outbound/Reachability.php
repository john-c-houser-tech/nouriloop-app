<?php

declare(strict_types=1);

namespace App\Outbound;

use App\Entity\User;
use App\Service\SubscriptionService;

/**
 * Whether a user is reachable (see CONTEXT.md) for a scheduled message: the one
 * place the digest, goal check-ins and the Settings form read the rules from.
 * Transactional mail (sign-in links, reset, verification) is not a scheduled
 * message and doesn't come through here: it exists to reach unverified inboxes.
 *
 *  - Email: the user chose email, and the address is verified (protects sender
 *    reputation from bouncing at unconfirmed or mistyped inboxes).
 *  - SMS: the user chose SMS, a phone number is on file, and TCPA/CTIA consent
 *    is recorded. Since tiering a Free user can store the SMS channel without
 *    ever seeing the consent controls, so the channel alone never implies
 *    consent.
 *  - The entitlement the purpose needs, checked at send time so a lapsed
 *    subscription quietly drops to in-app (the stored preference is never
 *    mutated): digest email is free, any SMS needs `sms` (Loop+ only, the one
 *    channel with a real per-message cost), and check-ins also need
 *    `checkin_delivery` (either paid plan).
 */
final class Reachability
{
    public const MISSING_PHONE = 'phone';
    public const MISSING_CONSENT = 'consent';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function canReach(User $user, OutboundChannel $channel, MessagePurpose $purpose): bool
    {
        $choice = $user->getNotificationChannel();

        $chosenAndReady = match ($channel) {
            OutboundChannel::Email => $choice->includesEmail() && $user->isEmailVerified(),
            OutboundChannel::Sms   => $choice->includesSms()
                && self::smsMissing($user->getPhoneNumber(), $user->getSmsConsentAt() !== null) === [],
        };

        return $chosenAndReady && $this->entitled($user, $channel, $purpose);
    }

    /**
     * What SMS still needs before it can be used: a phone number and recorded
     * consent. Takes the two facts rather than a user, so the Settings form can
     * ask about what was just posted. An empty number counts as none.
     *
     * @return list<self::MISSING_*>
     */
    public static function smsMissing(?string $phoneNumber, bool $consented): array
    {
        $missing = [];
        if ($phoneNumber === null || trim($phoneNumber) === '') {
            $missing[] = self::MISSING_PHONE;
        }
        if (!$consented) {
            $missing[] = self::MISSING_CONSENT;
        }

        return $missing;
    }

    private function entitled(User $user, OutboundChannel $channel, MessagePurpose $purpose): bool
    {
        return match ([$purpose, $channel]) {
            [MessagePurpose::Digest, OutboundChannel::Email] => true,
            [MessagePurpose::Digest, OutboundChannel::Sms] => $this->subscriptions->hasFeature($user, 'sms'),
            [MessagePurpose::CheckIn, OutboundChannel::Sms] => $this->subscriptions->hasFeature($user, 'sms')
                && $this->subscriptions->hasFeature($user, 'checkin_delivery'),
            default => $this->subscriptions->hasFeature($user, 'checkin_delivery'),
        };
    }
}
