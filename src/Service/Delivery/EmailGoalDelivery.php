<?php

declare(strict_types=1);

namespace App\Service\Delivery;

use App\Entity\GoalCheckin;
use App\Enum\NotificationChannel;
use App\Outbound\MessagePurpose;
use App\Outbound\OutboundChannel;
use App\Outbound\OutboundSender;
use App\Outbound\Reachability;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The baseline channel: emails a goal check-in to the user when they are
 * reachable by email (Reachability), through the same isolated send as the
 * digest (OutboundSender; the global From header and Postfix→IONOS relay apply).
 */
class EmailGoalDelivery implements GoalDeliveryInterface
{
    public function __construct(
        private readonly OutboundSender $sender,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $chartsDir,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private readonly Reachability $reachability,
    ) {
    }

    public function supports(NotificationChannel $channel): bool
    {
        return $channel->includesEmail();
    }

    public function deliver(GoalCheckin $checkin): void
    {
        $goal = $checkin->getGoal();
        $user = $goal?->getUser();
        if ($goal === null || $user === null) {
            return;
        }

        // A verified address and the Loop+ checkin_delivery entitlement; otherwise
        // the in-app delivery still records the check-in.
        if (!$this->reachability->canReach($user, OutboundChannel::Email, MessagePurpose::CheckIn)) {
            return;
        }

        $email = (new TemplatedEmail())
            ->to(new Address((string) $user->getEmail(), (string) $user->getDisplayName()))
            ->subject($this->translator->trans('delivery.checkin.subject', ['name' => $goal->getName()]))
            ->htmlTemplate('email/goal_checkin.html.twig')
            ->context([
                'user'    => $user,
                'goal'    => $goal,
                'def'     => $goal->getDefinition(),
                'checkin' => $checkin,
                'url'     => $this->urlGenerator->generate(
                    'app_goals_show',
                    ['id' => $goal->getId()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'hasChart' => $checkin->getChartPath() !== null,
            ]);

        // Embed the chart inline (cid:chart) so it renders in the email without a
        // round-trip to the server; the web goal page uses the path directly. The
        // file is located in the configured charts dir by basename, so it survives
        // the charts dir moving (e.g. out of the public docroot).
        $chartPath = $checkin->getChartPath();
        if ($chartPath !== null) {
            $chartFile = $this->chartsDir . '/' . basename($chartPath);
            if (is_file($chartFile)) {
                $email->embedFromPath($chartFile, 'chart');
            }
        }

        // Isolated like every scheduled send: a transport failure is a warning
        // here, not an exception for the dispatcher to log as an error.
        $this->sender->sendEmail($email, $user, 'Goal check-in');
    }
}
