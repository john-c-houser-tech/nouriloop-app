<?php

declare(strict_types=1);

namespace App\Service\Delivery;

use App\Entity\GoalCheckin;
use App\Enum\NotificationCategory;
use App\Enum\NotificationChannel;
use App\Service\InAppNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * In-app delivery: drops a goal check-in into the user's notification center (the
 * navbar bell + /notifications inbox). The check-in is also persisted on the goal
 * page; this surfaces it proactively. Participates for every channel — in-app is
 * always available regardless of the user's email/SMS preference.
 *
 * Only *deliverable* check-ins (Achieved/On-track/Off-track) create a notification;
 * no-data and error runs are recorded on the goal page but don't ping the bell, so a
 * disengaged user isn't nagged (mirrors the email gating in GoalCheckinStatus).
 */
class InAppGoalDelivery implements GoalDeliveryInterface
{
    public function __construct(
        private readonly InAppNotifier $notifier,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ) {
    }

    public function supports(NotificationChannel $channel): bool
    {
        // The persisted check-in is always available in-app; the bell notification
        // hooks in here for every channel.
        return true;
    }

    public function deliver(GoalCheckin $checkin): void
    {
        $goal = $checkin->getGoal();
        $user = $goal?->getUser();
        if ($goal === null || $user === null) {
            return;
        }

        if (!$checkin->getStatus()->isDeliverable()) {
            $this->logger->debug('Goal check-in {goal} not bell-worthy (status {status})', [
                'goal'   => $goal->getId(),
                'status' => $checkin->getStatus()->value,
            ]);

            return;
        }

        $link = $this->urlGenerator->generate('app_goals_show', ['id' => $goal->getId()]);

        $this->notifier->notify(
            $user,
            NotificationCategory::GoalCheckin,
            $this->translator->trans('delivery.checkin.inapp_title', [
                'goal'   => $goal->getName(),
                'status' => $this->translator->trans($checkin->getStatus()->transKey()),
            ]),
            $this->snippet($checkin->getSummaryText()),
            $link,
            $checkin->getRunAt(),
        );
    }

    /** First sentence / ~140 chars of the narrative for the inbox preview. */
    private function snippet(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $text = trim($text);
        if (mb_strlen($text) <= 140) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 140), " \t\n\r.,;:") . '…';
    }
}
