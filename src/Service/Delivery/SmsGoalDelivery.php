<?php

declare(strict_types=1);

namespace App\Service\Delivery;

use App\Entity\GoalCheckin;
use App\Enum\NotificationChannel;
use App\Outbound\MessagePurpose;
use App\Outbound\OutboundChannel;
use App\Outbound\OutboundSender;
use App\Outbound\Reachability;

/**
 * Delivers a goal check-in to the user by SMS via Twilio when they are
 * reachable there (Reachability: phone, TCPA/CTIA consent, checkin_delivery),
 * through the same isolated send as the digest (OutboundSender).
 */
class SmsGoalDelivery implements GoalDeliveryInterface
{
    public function __construct(
        private readonly OutboundSender $sender,
        private readonly Reachability $reachability,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ) {
    }

    public function supports(NotificationChannel $channel): bool
    {
        return $channel->includesSms();
    }

    public function deliver(GoalCheckin $checkin): void
    {
        $goal = $checkin->getGoal();
        $user = $goal?->getUser();

        // Phone, TCPA/CTIA consent and the Loop+ checkin_delivery entitlement;
        // otherwise the in-app delivery still records the check-in.
        if ($user === null || !$this->reachability->canReach($user, OutboundChannel::Sms, MessagePurpose::CheckIn)) {
            return;
        }

        $body = $this->translator->trans('delivery.checkin.sms', [
            'name'   => (string) $goal?->getName(),
            'status' => $this->translator->trans($checkin->getStatus()->transKey()),
        ]);

        $this->sender->sendSms($user, $body, 'Goal check-in');
    }
}
