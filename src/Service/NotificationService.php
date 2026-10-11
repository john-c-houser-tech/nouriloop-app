<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\NotificationLog;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Enum\NotificationType;
use App\Intake\DailyIntake;
use App\Outbound\MessagePurpose;
use App\Outbound\OutboundChannel;
use App\Outbound\OutboundSender;
use App\Outbound\Reachability;
use App\Repository\HealthMetricRepository;
use App\Repository\MealEntryRepository;
use App\Repository\NotificationLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Evaluates the three notification conditions for a user and emails a digest of
 * whichever notices are due, honouring per-user preferences and per-type cooldowns.
 */
class NotificationService
{
    private const EVENING_HOUR = 18;
    private const STALE_DAYS = 7;
    private const CAL_MIN = 1200.0;
    private const CAL_MAX = 3500.0;
    private const CAL_LOW_FACTOR = 0.6;
    private const CAL_HIGH_FACTOR = 1.5;
    private const CAL_AVG_DAYS = 30;
    private const CAL_MIN_HISTORY = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OutboundSender $sender,
        private readonly LoggerInterface $logger,
        private readonly HealthMetricRepository $healthMetrics,
        private readonly MealEntryRepository $meals,
        private readonly DailyIntake $intake,
        private readonly NotificationLogRepository $log,
        private readonly SupplementRecommendationService $supplements,
        private readonly UserTime $userTime,
        private readonly InAppNotifier $inAppNotifier,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly LocaleFormatter $localeFormatter,
        private readonly Reachability $reachability,
    ) {
    }

    /**
     * @return list<string> labels of the notices that were due (and emailed unless $dryRun)
     */
    public function sendDueNotifications(User $user, \DateTimeImmutable $now, bool $dryRun = false): array
    {
        // The WHOLE pipeline runs in the recipient's locale — the supplement
        // check builds its payload via SupplementRecommendationService, whose
        // labels/suggestions translate at computeGaps() time, so the checks
        // must already be inside the switch (caught on PR #148 review).
        return $this->localeSwitcher->runWithLocale(
            $user->getLocale()->value,
            fn (): array => $this->doSendDueNotifications($user, $now, $dryRun),
        );
    }

    /** @return list<string> */
    private function doSendDueNotifications(User $user, \DateTimeImmutable $now, bool $dryRun): array
    {
        $notices = [];

        if (($noMeals = $this->checkNoMeals($user, $now)) !== null) {
            $notices[NotificationType::NoMealsToday->value] = $noMeals;
        }
        if (($stale = $this->checkStaleMetrics($user, $now)) !== null) {
            $notices[NotificationType::StaleMetrics->value] = $stale;
        }
        if (($calorie = $this->checkCalories($user, $now)) !== null) {
            $notices[NotificationType::CalorieRange->value] = $calorie;
        }
        if (($supplement = $this->checkSupplements($user, $now)) !== null) {
            $notices[NotificationType::Supplements->value] = $supplement;
        }

        if ($notices === []) {
            return [];
        }

        // Email and SMS follow the reachability rules (verified address; phone,
        // consent and the sms_digest entitlement); in-app always receives.
        $sendEmail = $this->reachability->canReach($user, OutboundChannel::Email, MessagePurpose::Digest);
        $sendSms = $this->reachability->canReach($user, OutboundChannel::Sms, MessagePurpose::Digest);

        if (!$dryRun) {
            // The in-app notification center always receives the due notices, no matter
            // the user's email/SMS channel — it's the single in-app home for everything
            // NouriLoop surfaces (so an SMS-only user with no phone on file still sees them).
            foreach ($notices as $typeValue => $payload) {
                $this->createInAppNotice($user, NotificationType::from($typeValue), $payload);
            }
            if ($sendEmail) {
                $this->sendDigestEmail($user, $notices);
            }
            if ($sendSms) {
                $this->sendDigestSms($user, $notices);
            }
            // Cooldown log: written whenever a notice is delivered to any surface
            // (in-app always is), so the same notice doesn't re-fire next hour.
            foreach (array_keys($notices) as $typeValue) {
                $this->em->persist(new NotificationLog($user, NotificationType::from($typeValue), $now));
            }
            $this->em->flush();
        }

        return array_map(
            static fn (string $t): string => NotificationType::from($t)->label(),
            array_keys($notices),
        );
    }

    /**
     * Mirrors one digest notice into the in-app notification center, deep-linked to
     * the page where the user can act on it.
     *
     * @param array<string, mixed> $payload the notice's data (same shape the email uses)
     */
    private function createInAppNotice(User $user, NotificationType $type, array $payload): void
    {
        [$title, $body, $route] = match ($type) {
            NotificationType::NoMealsToday => [
                $this->translator->trans('delivery.inapp.no_meals_title'),
                $this->translator->trans('delivery.inapp.no_meals_body', ['date' => $payload['date'] ?? 'today']),
                'app_meal_log',
            ],
            NotificationType::StaleMetrics => [
                $this->translator->trans('delivery.inapp.stale_title'),
                isset($payload['last'])
                    ? $this->translator->trans('delivery.inapp.stale_body', ['date' => (string) $payload['last']])
                    : $this->translator->trans('delivery.inapp.stale_body_none'),
                'app_health_metrics',
            ],
            NotificationType::CalorieRange => [
                $this->translator->trans('delivery.inapp.calorie_title', [
                    'direction' => $payload['direction'] ?? 'high',
                    'date'      => $payload['date'] ?? '',
                ]),
                $this->calorieNoticeBody($payload),
                'app_trends',
            ],
            NotificationType::Supplements => [
                $this->translator->trans('delivery.inapp.supp_title'),
                $this->supplementNoticeBody($payload),
                'app_supplements',
            ],
        };

        $this->inAppNotifier->notify(
            $user,
            NotificationCategory::fromNotificationType($type),
            trim($title),
            $body,
            $this->urlGenerator->generate($route),
        );
    }

    /** @param array<string, mixed> $payload */
    private function calorieNoticeBody(array $payload): string
    {
        $value = (int) ($payload['value'] ?? 0);
        $direction = $payload['direction'] ?? 'low';
        if (($payload['average'] ?? null) !== null) {
            return $this->translator->trans('delivery.inapp.calorie_body_avg', [
                'value'     => $value,
                'direction' => $direction,
                'average'   => (int) $payload['average'],
            ]);
        }

        return $this->translator->trans('delivery.inapp.calorie_body', ['value' => $value, 'direction' => $direction]);
    }

    /** @param array<string, mixed> $payload */
    private function supplementNoticeBody(array $payload): string
    {
        $gaps = $payload['gaps'] ?? [];
        $labels = array_map(static fn (array $g): string => (string) $g['label'], $gaps);
        $count = count($labels);
        if ($count === 0) {
            return $this->translator->trans('delivery.inapp.supp_body_none');
        }

        return $this->translator->trans('delivery.inapp.supp_body', [
            'count' => $count,
            'list'  => implode(', ', array_slice($labels, 0, 3)) . ($count > 3 ? ', …' : ''),
        ]);
    }

    private function isDue(User $user, NotificationType $type, \DateTimeImmutable $now): bool
    {
        if (!$user->wantsNotification($type)) {
            return false;
        }

        $since = $now->modify('-' . $type->cooldownDays() . ' days');

        return !$this->log->hasSentSince($user, $type, $since);
    }

    /**
     * Evening nudge: nothing logged today. Only fires from EVENING_HOUR onward so
     * the user has had the day to log meals before being reminded.
     *
     * @return array{date: string}|null
     */
    private function checkNoMeals(User $user, \DateTimeImmutable $now): ?array
    {
        $localNow = $this->userTime->now($user, $now);
        if ((int) $localNow->format('G') < self::EVENING_HOUR) {
            return null;
        }
        if (!$this->isDue($user, NotificationType::NoMealsToday, $now)) {
            return null;
        }
        $todayStart = $this->userTime->startOfToday($user, $now);
        if ($this->meals->findByUserAndDateRange($user, $todayStart, $todayStart->modify('+1 day')) !== []) {
            return null;
        }

        return ['date' => $this->localeFormatter->date($localNow, 'MMMd')];
    }

    /**
     * @return array{last: string|null}|null
     */
    private function checkStaleMetrics(User $user, \DateTimeImmutable $now): ?array
    {
        if (!$this->isDue($user, NotificationType::StaleMetrics, $now)) {
            return null;
        }

        // recorded_at is the user's local wall-clock (offset dropped at import), so
        // compare it against the user's local "now", both as wall-clock strings.
        // Comparing the parsed value to the server instant skewed the cutoff by the
        // user's offset from the server zone — up to ~a day at the far zones.
        $latest = $this->healthMetrics->findLatestRecordedAt($user);
        $cutoff = $this->userTime->now($user, $now)
            ->modify('-' . self::STALE_DAYS . ' days')
            ->format('Y-m-d H:i:s');
        if ($latest !== null && $latest->format('Y-m-d H:i:s') >= $cutoff) {
            return null;
        }

        return ['last' => $latest !== null ? $this->localeFormatter->date($latest, 'yMMMd') : null];
    }

    /**
     * @return array{value: int, average: int|null, direction: string, date: string}|null
     */
    private function checkCalories(User $user, \DateTimeImmutable $now): ?array
    {
        if (!$this->isDue($user, NotificationType::CalorieRange, $now)) {
            return null;
        }

        $todayStart = $this->userTime->startOfToday($user, $now);
        $yStart = $todayStart->modify('-1 day');
        $yLabel = $yStart->format('Y-m-d');

        // Nothing logged yesterday — can't judge the day.
        $yesterday = $this->intake->days($user, $yStart, $todayStart)[$yLabel] ?? null;
        if ($yesterday === null) {
            return null;
        }
        $value = $yesterday->total('calories') ?? 0.0;

        // Trailing daily-total average over the days before yesterday.
        $avgFrom = $yStart->modify('-' . self::CAL_AVG_DAYS . ' days');
        $days = $this->intake->days($user, $avgFrom, $yStart);
        $average = null;
        if (count($days) >= self::CAL_MIN_HISTORY) {
            $calories = array_map(static fn ($d): float => $d->total('calories') ?? 0.0, $days);
            $average = array_sum($calories) / count($days);
        }

        $low = $value < self::CAL_MIN;
        $high = $value > self::CAL_MAX;
        if ($average !== null) {
            $low = $low || $value < self::CAL_LOW_FACTOR * $average;
            $high = $high || $value > self::CAL_HIGH_FACTOR * $average;
        }

        if (!$low && !$high) {
            return null;
        }

        return [
            'value'     => (int) round($value),
            'average'   => $average !== null ? (int) round($average) : null,
            'direction' => $low ? 'low' : 'high',
            'date'      => $this->localeFormatter->date($yStart, 'MMMd'),
        ];
    }

    /**
     * @return array{gaps: list<array{label: string, pct: float, suggestion: string}>}|null
     */
    private function checkSupplements(User $user, \DateTimeImmutable $now): ?array
    {
        if (!$this->isDue($user, NotificationType::Supplements, $now)) {
            return null;
        }

        $gaps = $this->supplements->getRecommendations($user, 7);
        if ($gaps === []) {
            return null;
        }

        return ['gaps' => array_slice($gaps, 0, 5)];
    }

    /**
     * @param array<string, mixed> $notices
     */
    private function sendDigestEmail(User $user, array $notices): void
    {
        $email = (new TemplatedEmail())
            ->to(new Address((string) $user->getEmail(), (string) $user->getDisplayName()))
            ->subject($this->translator->trans('delivery.digest.subject'))
            ->htmlTemplate('email/notifications.html.twig')
            ->context([
                'user'       => $user,
                'noMeals'    => $notices[NotificationType::NoMealsToday->value] ?? null,
                'stale'      => $notices[NotificationType::StaleMetrics->value] ?? null,
                'calorie'    => $notices[NotificationType::CalorieRange->value] ?? null,
                'supplement' => $notices[NotificationType::Supplements->value] ?? null,
            ]);

        // Isolated: the in-app notices already exist, so a mail failure must not
        // skip the cooldown log after it, or the next hourly run re-creates
        // duplicate bell notices for this user.
        $this->sender->sendEmail($email, $user, 'Digest');
    }

    /**
     * @param array<string, mixed> $notices
     */
    private function sendDigestSms(User $user, array $notices): void
    {
        $this->sender->sendSms($user, $this->buildSmsBody($notices), 'Digest');
    }

    /**
     * @param array<string, mixed> $notices
     */
    private function buildSmsBody(array $notices): string
    {
        $parts = [];

        if (isset($notices[NotificationType::NoMealsToday->value])) {
            $parts[] = $this->translator->trans('delivery.sms.no_meals');
        }
        if (isset($notices[NotificationType::StaleMetrics->value])) {
            $parts[] = $this->translator->trans('delivery.sms.stale');
        }
        if (($c = $notices[NotificationType::CalorieRange->value] ?? null) !== null) {
            $parts[] = $this->translator->trans('delivery.sms.calories', [
                'date'      => $c['date'],
                'value'     => (int) $c['value'],
                'direction' => $c['direction'],
            ]);
        }
        if (($s = $notices[NotificationType::Supplements->value] ?? null) !== null) {
            $parts[] = $this->translator->trans('delivery.sms.supplements', ['count' => count($s['gaps'])]);
        }

        // Kept terse so even an all-four digest stays within one SMS segment (160 chars).
        // ucfirst is ASCII-safe here: the catalog SMS parts start unaccented by design.
        return $this->translator->trans('delivery.sms.wrapper', ['body' => ucfirst(implode('; ', $parts))]);
    }
}
