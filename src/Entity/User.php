<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationChannel;
use App\Enum\NotificationType;
use App\Enum\SubscriptionTier;
use App\Enum\SupportedLocale;
use App\Enum\UnitSystem;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uq_user_stripe_customer', columns: ['stripe_customer_id'])]
#[ORM\UniqueConstraint(name: 'uq_user_stripe_subscription', columns: ['stripe_subscription_id'])]
// Covers the registrations-by-campaign counts query (range on created_at, group by campaign).
#[ORM\Index(name: 'idx_user_created_campaign', columns: ['created_at', 'utm_campaign'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const DEFAULT_TIMEZONE = 'America/Los_Angeles';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    private ?string $displayName = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(targetEntity: MealEntry::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $mealEntries;

    #[ORM\OneToMany(targetEntity: HealthMetric::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $healthMetrics;

    /** The invitation code this user registered with (single-use), if any. */
    #[ORM\OneToOne(mappedBy: 'usedBy', targetEntity: InvitationCode::class)]
    private ?InvitationCode $invitationCode = null;

    // Notifications are opt-in (default off) so users — especially for SMS —
    // receive nothing until they explicitly enable each type.
    #[ORM\Column(options: ['default' => false])]
    private bool $notifyNoMeals = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $notifyStaleMetrics = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $notifyCalorieRange = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $notifySupplements = false;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 5, enumType: NotificationChannel::class, options: ['default' => 'email'])]
    private NotificationChannel $notificationChannel = NotificationChannel::Email;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $smsConsentAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    // Server-frame instant of the user's most recent interactive login (form
    // login or remember-me; not API-token auth). NULL until they next log in.
    // Drives the admin user list's "active user" engagement indicator.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    // First-login onboarding overlay state. onboardingCompletedAt is stamped once all
    // three *required* steps (meal, goal, notifications) are done; null = still active.
    // onboardingDismissed lets the user permanently hide the overlay (Settings toggle),
    // overriding completion. The per-step timestamps drive the progress pips; the health
    // step is optional ("extra credit") and excluded from the completion check.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $onboardingCompletedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $onboardingDismissed = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $onboardingMealAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $onboardingGoalAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $onboardingNotificationsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $onboardingHealthAt = null;

    // Set when the user requests self-service account deletion (Settings ▸
    // Account); the account stays fully functional during the grace period —
    // see App\Service\AccountDeletionService. Null = no pending deletion.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletionRequestedAt = null;

    // Proof the user controls their inbox (soft verification — see
    // App\Service\EmailVerifier for the stamp points). Null = unverified: the app
    // stays fully usable, but scheduled email sends (digest, goal check-ins) are
    // withheld to protect deliverability. The launch migration backfilled all
    // pre-existing users as verified.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    // IANA timezone id (e.g. 'America/New_York'). Drives every per-user
    // "today"/"now" boundary and the local time their data is displayed/bucketed
    // in. Defaults to the historical server zone so existing rows behave as before.
    #[ORM\Column(length: 64, options: ['default' => self::DEFAULT_TIMEZONE])]
    private string $timezone = self::DEFAULT_TIMEZONE;

    // Display preference for weight (kg/lb) and blood glucose (mmol/L per mg/dL).
    // Storage stays canonical; see App\Service\UnitConverter. New users default
    // from their timezone (US → imperial) at registration.
    #[ORM\Column(length: 10, enumType: UnitSystem::class, options: ['default' => 'metric'])]
    private UnitSystem $unitSystem = UnitSystem::Metric;

    // UI/AI language preference (i18n Phase 1), stored as a full locale tag
    // (`es_MX`). LocaleSubscriber sets it on every authenticated request; new
    // users default from Accept-Language at registration (Settings ▸ Language
    // to change). Display/prompt language only — never affects storage.
    #[ORM\Column(length: 10, enumType: SupportedLocale::class, options: ['default' => 'en'])]
    private SupportedLocale $locale = SupportedLocale::English;

    // Opt-in "GLP-1 mode": tailors tracking for users on GLP-1 medications
    // (protein-focus dashboard card, nausea-trigger insights, small-portion
    // guidance). Off by default; enabling it also enables the nausea and
    // fullness/satiety symptom check-ins.
    #[ORM\Column(options: ['default' => false])]
    private bool $glp1Mode = false;

    // AI narrative voice preferences — three independent 1–5 dials the user tunes on
    // Settings ▸ Voice & tone. 3 is the neutral default (today's voice). Injected into
    // the coaching-narrative system prompts via TonePromptBuilder; style only, never
    // overrides accuracy or the educational-not-medical-advice framing.
    #[ORM\Column(type: 'smallint', options: ['default' => 3])]
    private int $toneHumor = 3;

    #[ORM\Column(type: 'smallint', options: ['default' => 3])]
    private int $toneWarmth = 3;

    #[ORM\Column(type: 'smallint', options: ['default' => 3])]
    private int $toneEducation = 3;

    // Subscription billing (Stripe). Free is the default for every account; paid
    // tiers/state are synced from Stripe via webhooks. Feature gating goes through
    // SubscriptionService, which applies grace-period / lazy-downgrade rules — never
    // read $subscriptionTier directly for entitlement decisions.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 20, enumType: SubscriptionTier::class, options: ['default' => 'free'])]
    private SubscriptionTier $subscriptionTier = SubscriptionTier::Free;

    // Mirrors the Stripe subscription status (trialing / active / past_due /
    // canceled / incomplete); null for a user who never started a subscription.
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $subscriptionStatus = null;

    // Current paid period end — the last instant of paid access, used for the
    // grace period after a cancellation.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $subscriptionPeriodEnd = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    // Complimentary entitlement, independent of Stripe billing — used to
    // grandfather beta testers. When set (and not past compExpiresAt), it overrides
    // the Stripe tier in SubscriptionService::effectiveTier. compExpiresAt null =
    // permanent (existing beta cohort); a date = time-limited (new beta invites get
    // SubscriptionService::BETA_COMP_DURATION). Granted via the grandfather
    // command (permanent) and beta-tester invitation codes (time-limited).
    #[ORM\Column(length: 20, enumType: SubscriptionTier::class, nullable: true)]
    private ?SubscriptionTier $compTier = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $compExpiresAt = null;

    // First-touch marketing attribution, captured from the landing URL's query
    // string at registration (App\Service\AttributionCapture). Null = the visitor
    // arrived without campaign parameters (direct, organic, or a return visit
    // after the session expired). Read only in aggregate — the counts endpoint
    // (Api\MetricsApiController) groups by campaign and never exposes a row.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $utmSource = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $utmMedium = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $utmCampaign = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $utmContent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gclid = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->mealEntries = new ArrayCollection();
        $this->healthMetrics = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, MealEntry>
     */
    public function getMealEntries(): Collection
    {
        return $this->mealEntries;
    }

    /**
     * @return Collection<int, HealthMetric>
     */
    public function getHealthMetrics(): Collection
    {
        return $this->healthMetrics;
    }

    public function isNotifyNoMeals(): bool
    {
        return $this->notifyNoMeals;
    }

    public function setNotifyNoMeals(bool $value): static
    {
        $this->notifyNoMeals = $value;

        return $this;
    }

    public function isNotifyStaleMetrics(): bool
    {
        return $this->notifyStaleMetrics;
    }

    public function setNotifyStaleMetrics(bool $value): static
    {
        $this->notifyStaleMetrics = $value;

        return $this;
    }

    public function isNotifyCalorieRange(): bool
    {
        return $this->notifyCalorieRange;
    }

    public function setNotifyCalorieRange(bool $value): static
    {
        $this->notifyCalorieRange = $value;

        return $this;
    }

    public function isNotifySupplements(): bool
    {
        return $this->notifySupplements;
    }

    public function setNotifySupplements(bool $value): static
    {
        $this->notifySupplements = $value;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function getNotificationChannel(): NotificationChannel
    {
        return $this->notificationChannel;
    }

    public function setNotificationChannel(NotificationChannel $channel): static
    {
        $this->notificationChannel = $channel;

        return $this;
    }

    public function getSmsConsentAt(): ?\DateTimeImmutable
    {
        return $this->smsConsentAt;
    }

    public function setSmsConsentAt(?\DateTimeImmutable $smsConsentAt): static
    {
        $this->smsConsentAt = $smsConsentAt;

        return $this;
    }

    public function wantsNotification(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::NoMealsToday => $this->notifyNoMeals,
            NotificationType::StaleMetrics => $this->notifyStaleMetrics,
            NotificationType::CalorieRange => $this->notifyCalorieRange,
            NotificationType::Supplements  => $this->notifySupplements,
        };
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function getDeletionRequestedAt(): ?\DateTimeImmutable
    {
        return $this->deletionRequestedAt;
    }

    public function setDeletionRequestedAt(?\DateTimeImmutable $deletionRequestedAt): static
    {
        $this->deletionRequestedAt = $deletionRequestedAt;

        return $this;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    /**
     * True while the first-login onboarding overlay should still be shown — until the
     * user dismisses it or resolves every step. The optional health step counts as
     * resolved once it's done *or* skipped (both stamp onboardingHealthAt), so the
     * overlay walks the user through the "Extra credit" step and then closes.
     */
    public function needsOnboarding(): bool
    {
        return !$this->onboardingDismissed && !$this->allOnboardingStepsResolved();
    }

    /** Every step (incl. the optional health step) done or skipped. */
    public function allOnboardingStepsResolved(): bool
    {
        return $this->onboardingMealAt !== null
            && $this->onboardingGoalAt !== null
            && $this->onboardingNotificationsAt !== null
            && $this->onboardingHealthAt !== null;
    }

    /**
     * Stamp a step as done (idempotent — keeps the first timestamp). Once all three
     * required steps are present, stamps onboardingCompletedAt. The optional 'health'
     * step never affects completion. Unknown $step throws (intentional guard).
     */
    public function markOnboardingStep(string $step, \DateTimeImmutable $at): void
    {
        match ($step) {
            'meal' => $this->onboardingMealAt ??= $at,
            'goal' => $this->onboardingGoalAt ??= $at,
            'notifications' => $this->onboardingNotificationsAt ??= $at,
            'health' => $this->onboardingHealthAt ??= $at,
        };

        if (
            $this->onboardingCompletedAt === null
            && $this->onboardingMealAt !== null
            && $this->onboardingGoalAt !== null
            && $this->onboardingNotificationsAt !== null
        ) {
            $this->onboardingCompletedAt = $at;
        }
    }

    public function getOnboardingCompletedAt(): ?\DateTimeImmutable
    {
        return $this->onboardingCompletedAt;
    }

    /**
     * Clears every per-step timestamp and the completion stamp (not `dismissed`) so
     * the onboarding overlay reappears — used by App\Service\AccountDeletionService
     * when a user deletes their data but keeps their account, so "start fresh"
     * really does start fresh.
     */
    public function resetOnboarding(): void
    {
        $this->onboardingMealAt = null;
        $this->onboardingGoalAt = null;
        $this->onboardingNotificationsAt = null;
        $this->onboardingHealthAt = null;
        $this->onboardingCompletedAt = null;
    }

    public function isOnboardingDismissed(): bool
    {
        return $this->onboardingDismissed;
    }

    public function setOnboardingDismissed(bool $onboardingDismissed): static
    {
        $this->onboardingDismissed = $onboardingDismissed;

        return $this;
    }

    public function getOnboardingMealAt(): ?\DateTimeImmutable
    {
        return $this->onboardingMealAt;
    }

    public function getOnboardingGoalAt(): ?\DateTimeImmutable
    {
        return $this->onboardingGoalAt;
    }

    public function getOnboardingNotificationsAt(): ?\DateTimeImmutable
    {
        return $this->onboardingNotificationsAt;
    }

    public function getOnboardingHealthAt(): ?\DateTimeImmutable
    {
        return $this->onboardingHealthAt;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getUnitSystem(): UnitSystem
    {
        return $this->unitSystem;
    }

    public function setUnitSystem(UnitSystem $unitSystem): static
    {
        $this->unitSystem = $unitSystem;

        return $this;
    }

    public function getLocale(): SupportedLocale
    {
        return $this->locale;
    }

    public function setLocale(SupportedLocale $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function isGlp1Mode(): bool
    {
        return $this->glp1Mode;
    }

    public function setGlp1Mode(bool $glp1Mode): static
    {
        $this->glp1Mode = $glp1Mode;

        return $this;
    }

    public function getToneHumor(): int
    {
        return $this->toneHumor;
    }

    public function setToneHumor(int $toneHumor): static
    {
        $this->toneHumor = $this->clampTone($toneHumor);

        return $this;
    }

    public function getToneWarmth(): int
    {
        return $this->toneWarmth;
    }

    public function setToneWarmth(int $toneWarmth): static
    {
        $this->toneWarmth = $this->clampTone($toneWarmth);

        return $this;
    }

    public function getToneEducation(): int
    {
        return $this->toneEducation;
    }

    public function setToneEducation(int $toneEducation): static
    {
        $this->toneEducation = $this->clampTone($toneEducation);

        return $this;
    }

    /** Tone dials are always 1–5; coerce stray input to the nearest valid value. */
    private function clampTone(int $value): int
    {
        return max(1, min(5, $value));
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;

        return $this;
    }

    public function getSubscriptionTier(): SubscriptionTier
    {
        return $this->subscriptionTier;
    }

    public function setSubscriptionTier(SubscriptionTier $subscriptionTier): static
    {
        $this->subscriptionTier = $subscriptionTier;

        return $this;
    }

    public function getSubscriptionStatus(): ?string
    {
        return $this->subscriptionStatus;
    }

    public function setSubscriptionStatus(?string $subscriptionStatus): static
    {
        $this->subscriptionStatus = $subscriptionStatus;

        return $this;
    }

    public function getSubscriptionPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->subscriptionPeriodEnd;
    }

    public function setSubscriptionPeriodEnd(?\DateTimeImmutable $subscriptionPeriodEnd): static
    {
        $this->subscriptionPeriodEnd = $subscriptionPeriodEnd;

        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): static
    {
        $this->trialEndsAt = $trialEndsAt;

        return $this;
    }

    public function getCompTier(): ?SubscriptionTier
    {
        return $this->compTier;
    }

    public function setCompTier(?SubscriptionTier $compTier): static
    {
        $this->compTier = $compTier;

        return $this;
    }

    public function getCompExpiresAt(): ?\DateTimeImmutable
    {
        return $this->compExpiresAt;
    }

    public function setCompExpiresAt(?\DateTimeImmutable $compExpiresAt): static
    {
        $this->compExpiresAt = $compExpiresAt;

        return $this;
    }

    public function getInvitationCode(): ?InvitationCode
    {
        return $this->invitationCode;
    }

    public function getUtmSource(): ?string
    {
        return $this->utmSource;
    }

    public function setUtmSource(?string $utmSource): static
    {
        $this->utmSource = $utmSource;

        return $this;
    }

    public function getUtmMedium(): ?string
    {
        return $this->utmMedium;
    }

    public function setUtmMedium(?string $utmMedium): static
    {
        $this->utmMedium = $utmMedium;

        return $this;
    }

    public function getUtmCampaign(): ?string
    {
        return $this->utmCampaign;
    }

    public function setUtmCampaign(?string $utmCampaign): static
    {
        $this->utmCampaign = $utmCampaign;

        return $this;
    }

    public function getUtmContent(): ?string
    {
        return $this->utmContent;
    }

    public function setUtmContent(?string $utmContent): static
    {
        $this->utmContent = $utmContent;

        return $this;
    }

    public function getGclid(): ?string
    {
        return $this->gclid;
    }

    public function setGclid(?string $gclid): static
    {
        $this->gclid = $gclid;

        return $this;
    }

    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->getRoles(), true);
    }

    /**
     * No-op: we never hold plaintext credentials on the entity (the password is already
     * hashed), so there is nothing to erase. The #[\Deprecated] attribute is how Symfony
     * 7.3 asks an empty eraseCredentials() to opt out of the deprecation that
     * symfony/security-http's AuthenticatorManager otherwise logs on every authentication.
     */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
