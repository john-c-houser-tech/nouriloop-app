---
description: "The evening digest, reachability rules, SMS via Twilio and TCPA consent, privacy/terms pages, the footer, and the in-app notification center."
paths:
  - "src/Service/NotificationService.php"
  - "src/Command/SendNotificationsCommand.php"
  - "src/Outbound/**"
  - "src/Service/InAppNotifier.php"
  - "src/Controller/NotificationController.php"
  - "src/Twig/NotificationExtension.php"
  - "src/Entity/Notification.php"
  - "src/Entity/NotificationLog.php"
  - "src/Enum/NotificationType.php"
  - "src/Enum/NotificationCategory.php"
  - "src/Enum/NotificationChannel.php"
  - "src/Form/NotificationPreferencesFormType.php"
  - "src/Controller/PrivacyController.php"
  - "src/Content/WellnessDisclaimers.php"
  - "src/Twig/DisclaimerExtension.php"
  - "templates/privacy/**"
  - "templates/notification/**"
  - "templates/email/notifications.html.twig"
---

# Scheduled notifications, legal pages and the in-app center — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

## Email & SMS notifications (scheduled)
Digest notifications for four conditions, evaluated per user by a console command
run hourly; each user is processed when their **local** hour hits 8 PM (`SEND_HOUR`),
so users in any timezone get their digest in their own evening (see Timezones).

- **Command:** `php bin/console app:send-notifications`
  (`src/Command/SendNotificationsCommand.php`). Flags: `--dry-run` (report only,
  no send/log), `--user=<email>` (single user), `--now="YYYY-MM-DD HH:MM"`
  (evaluate time-gated notices as if it were this time — for testing/replay),
  `--force` (skip the local-hour gate, for testing). `--now` here and in
  `app:run-goal-checkins` / `app:purge-deleted-accounts` goes through
  `App\Support\NowOption::parse`, which rejects an impossible date (`2026-02-30`) instead
  of letting PHP silently replay March 2. Per-type cooldowns
  (`NotificationLog`) keep it to one digest a day even though the command runs hourly.
  **Failure isolation (2026-09-30):** each user is processed in its own try/catch
  (one user's exception used to abort the run, so everyone after them missed that
  day's once-a-day local-8-PM window), and any failure makes the run exit `FAILURE`.
  The digest **email** send is wrapped like the SMS send already was: the in-app
  notices are created first, so a mail failure that skipped the cooldown log made the
  next hourly run re-create duplicate bell notices. Covered by
  `tests/Command/SendNotificationsCommandTest` and
  `tests/Service/NotificationServiceEmailFailureTest`.
- **Cron (run hourly):**
  `0 * * * * cd /path/to/project && php bin/console app:send-notifications`
- **Conditions** (`src/Service/NotificationService.php`):
  - *No meals today* — nothing logged that day; only fires from 18:00 onward
    (evening nudge), in the user's timezone via `UserTime` + `MealEntryRepository::findByUserAndDateRange()`.
  - *Stale metrics* — no health reading in > 7 days (or none at all). The cutoff is
    compared in the **user's wall-clock** (readings are stored that way); comparing to
    the server instant skewed it by the user's offset from the server zone (fixed
    2026-09-30, `tests/Service/NotificationServiceStaleMetricsTest`).
  - *Calorie out-of-range* — yesterday's total < 1,200 or > 3,500 kcal, or
    < 0.6× / > 1.5× the trailing 30-day daily average (relative check needs ≥ 5
    days of history). Days with no meals logged are skipped.
  - *Supplements* — reuses
    `SupplementRecommendationService::getRecommendations($user, 7)` (nutrients
    < 75% RDA over 7 days).
- **Who can be reached is one rule — `App\Outbound\Reachability` (2026-10-07).** The
  digest, goal check-in email/SMS and the Settings ▸ Notifications validation all read
  `canReach(user, OutboundChannel, MessagePurpose)`: email needs the email channel and a
  verified address; SMS needs the SMS channel, a phone (an empty one counts as none)
  and recorded TCPA consent; then the purpose's entitlement (digest email free, digest
  SMS `sms`, check-in email `checkin_delivery`, check-in SMS both — `sms` is Loop+ only,
  `checkin_delivery` either paid tier, since PR 7). Settings asks
  `Reachability::smsMissing(phone, consented)` about what was just posted, so its
  errors and the senders can't disagree. Sends go through `App\Outbound\OutboundSender`
  (`sendEmail`/`sendSms`): log, try, warn on failure, never throw. Transactional mail
  (sign-in links, reset, verification, welcome) is **not** a scheduled message and
  doesn't come through either: it exists to reach unverified inboxes. Covered by
  `tests/Outbound/ReachabilityTest` (every channel × purpose × user state) and
  `OutboundSenderTest`.
- **One digest** per run covering whichever notices are due, sent over the user's
  channels: email (`templates/email/notifications.html.twig`) and/or a compact SMS
  summary built in `NotificationService::buildSmsBody()`. The **email channel
  additionally requires a verified address** (see Email ownership verification under
  Auth — protects sender reputation; in-app is ungated). The **SMS channel gates on
  the `sms` entitlement (Loop+ only since PR 7)** — see Goal & notification tiering under
  Nutrition goals (2026-07-21; SMS is the only channel with real per-message cost, so
  it's the paid one; email + in-app stay free on every tier). Each due notice is
  **also** mirrored into the in-app notification center (see **In-app notification
  center** below), regardless of the email/SMS channel.
- **Per-user settings** on **Settings ▸ Notifications** (`/settings/notifications`,
  `SettingsController`): four on/off toggles (**default off / opt-in**, read via
  `User::wantsNotification()`), a delivery channel (Email / SMS / Both —
  `NotificationChannel` enum), and a phone number in E.164 format.
- **Dedup:** `NotificationLog` (table `notification_log`) + per-type cooldowns
  (no-meals & calorie 1 day, stale & supplements 7 days) in
  `NotificationType::cooldownDays()`.
- **SMS via Twilio** (Symfony Notifier + `symfony/twilio-notifier`). The texter is
  injected lazily and sends are wrapped: with no `TWILIO_DSN`, the SMS body is
  logged and skipped (email still goes out). Real creds go in `.env.local`:
  `TWILIO_DSN=twilio://SID:TOKEN@default?from=%2B1...` — **encode the `+` as `%2B`**
  (a literal `+` in the query decodes to a space), and use the **Live** Auth Token, not
  the Test one. **A2P 10DLC is approved and SMS is live** (both the digest and goal
  check-ins), sending from a campaign-registered number.
- Email and SMS are sent **synchronously** (Messenger leaves them unrouted), so no
  `messenger:consume` worker is required.
- **SMS consent (TCPA/CTIA):** enabling SMS requires a phone number **and** an
  affirmative consent checkbox; `SettingsController` enforces both and records
  `User::$smsConsentAt` on opt-in (cleared on opt-out). The public policy lives at
  **`/privacy/sms`** (`PrivacyController`, `templates/privacy/sms.html.twig`,
  `^/privacy` is `PUBLIC_ACCESS`). The page is live and complete at nouriloop.com/privacy/sms
  (effective June 9, 2026). **A2P 10DLC registration is complete (campaign approved).** A
  public, no-login preview of the opt-in screen lives at **`/privacy/sms-optin`**
  (`PrivacyController::smsOptin`), mirroring Settings ▸ Notifications for carrier review.
- **Health-data notice:** `/privacy/health` (`PrivacyController::health`,
  `templates/privacy/health.html.twig`), linked from the metrics **import form** and
  the **Health Metrics** page, and (since 2026-10-08) the registration page's legal line,
  which linked the SMS Privacy Policy before. Discloses processors (Anthropic/Twilio/email).
  ⚠️ **Keep §3's Anthropic list in step with what the Claude calls actually send** — it
  names each feature's inputs (meal/recipe/supplement photos, plus descriptions, notes,
  follow-up answers and ingredient corrections from the refine rounds; glucose
  readings for Pattern Coach; nutrition averages, inflammation score, gaps and goal
  progress for goals and Predict; help questions). Until 2026-10-08 it named only meal
  photos, so Pattern Coach's glucose readings went to Anthropic undisclosed. A new
  Claude surface, or new data in an existing prompt, means a §3 edit and a new "Last
  updated" date. §1 covers the *analysis* of meals, not the photos themselves (John's
  classification, 2026-10-08). The cookie banner (`chrome.cookies.text`) names Google
  Analytics **and the Meta Pixel** (one banner governs both — Accept grants both) and
  says meals and health data never reach them; it used to say "No health
  data is ever shared", which read as contradicting this notice. Live and
  complete at nouriloop.com/privacy/health (effective June 9, 2026) — and note it's a
  consumer health-data notice, **not** a HIPAA Notice of Privacy Practices (HIPAA covers
  covered entities/business associates, which a D2C wellness app generally isn't).
- **Terms of Service:** public at `/privacy/terms` (`PrivacyController::terms`,
  `templates/privacy/terms.html.twig`), linked from the footer.
- **Rotating footer disclaimer:** the global footer (`templates/base.html.twig`) shows
  **one of 20 humorous "not medical advice" disclaimers**, picked at random per page load,
  above the copyright. Strings live in **`App\Content\WellnessDisclaimers`** (two sets:
  `LONG` for the footer, `SHORT` one-liners for tight spots), surfaced via the
  **`wellness_disclaimer('long'|'short')`** Twig function (`App\Twig\DisclaimerExtension`).
  The footer reserves a **fixed** two-line box (three on phones, at a smaller font) with the
  text vertically centered, so the random pick never makes the footer bounce; the `.wellness-disclaimer`
  column is **36rem** wide (narrowed from 46rem so the longest picks still hold two balanced lines
  rather than a wide line + an orphan word) and rendered in the palette **deep-blue (`--deep-blue`),
  weight 500**, so it reads as a distinct note above the muted-gray copyright. The `LONG` set
  is curated to ≤ ~150 chars to fit without clipping (a `WellnessDisclaimersTest` enforces the
  budget, and `phpcs.xml.dist` excludes that prose-data file from the line-length sniff). This
  is **supplementary** — the canonical *serious* disclaimers in the high-stakes contexts
  (inflammation score, glucose insights, Predict, goals) are unchanged. Covered by
  `tests/E2E/FooterDisclaimerTest` + `tests/Content/WellnessDisclaimersTest`.
- **Release version (footer):** the copyright line shows the release version just before
  `© NouriLoop LLC` when set. Driven by the **`APP_VERSION`** env → the **`app_version`**
  Twig global (`config/packages/twig.yaml`, same pattern as `google_analytics_id`); **empty
  in dev/test/CI → nothing rendered** (no orphan separator). Versioning is **git-tag-based,
  stamped at deploy**: the deploy script writes `git describe --tags --always` into the
  server's `.env.local` (see Deploying an update), so cutting a **GitHub release** (= a tag)
  is all that's needed — the next deploy picks it up, no manual code bump.

### In-app notification center (bell + inbox)
A navbar **bell with an unread badge + dropdown** and a full **`/notifications` inbox**,
so the in-app surface is the single home for everything NouriLoop sends — goal check-ins
**and** the digest notices — independent of the user's email/SMS channel.

- **Model:** `Notification` entity (per-user; table `notification`, migration
  `Version20260627182550`): `category` (`App\Enum\NotificationCategory` →
  `goal_checkin`/`no_meals_today`/`stale_metrics`/`calorie_range`/`supplements`, each
  mapping to a Bootstrap icon), `title`, `body`, `linkPath` (a deep link), `createdAt`,
  `readAt` (null = unread, drives the badge). FK `ON DELETE CASCADE` **and** added to the
  admin user-delete child-table list. Index `idx_notification_user_created`.
- **Write path — `App\Service\InAppNotifier::notify(...)`** persists+flushes one row; the
  single shaper used by both producers:
  - **Goal check-ins:** `InAppGoalDelivery` (the old no-op stub, now filled in) — deep-links
    to `app_goals_show`, only for *deliverable* statuses (see Goal delivery above).
  - **Digest notices:** `NotificationService::createInAppNotice()` mirrors each **due** notice
    into the inbox, deep-linked to where the user acts (no-meals→Log, stale→Health Metrics,
    calorie→Insights, supplements→Supplements). Created whenever a notice is *due* (same
    opt-in toggle + cooldown gating), **regardless of email/SMS deliverability** — so an
    SMS-only user with no phone on file still sees them in-app. The cooldown `NotificationLog`
    is now written whenever a notice is delivered to **any** surface (in-app always is).
- **UI:** `App\Twig\NotificationExtension` exposes `unread_notification_count()` +
  `recent_notifications(5)` (memoized per request, empty/0 when logged out — same lazy
  pattern as `has_feature()`), driving the bell + dropdown in `base.html.twig` (logged-in
  only). The bell's `{% set %}` vars are **namespaced `bellUnread`/`bellRecent`** —
  a parent-template `{% set %}` leaks into child blocks, so a plain `recent`/`unread`
  would shadow a page variable (it did: `/symptoms` passes `recent`, and any user *with
  notifications* 500'd until this was fixed; `tests/E2E/NotificationCenterTest` guards it).
  `NotificationController` (`GET /notifications`, in the **More** nav) lists the
  latest 50; **viewing the inbox marks everything read** (clears the badge — the friendly
  Gmail-style behaviour), but rows unread *on arrival* stay highlighted as "New" on that
  render so the user can tell what's new. `NotificationRepository` has
  `countUnread`/`findRecent`/`markAllRead` (one bulk UPDATE).
- **Click-through is indirected** (`GET /notifications/{id}/go`): both the inbox and the
  bell-dropdown rows link through `go()` rather than straight to the deep link, so a click
  marks **that one** item read and is ownership-checked. If the target has since been
  deleted, it lands on the inbox with a "no longer available" `info` flash instead of a
  raw 404 — only `goal_checkin` links point at a per-resource page that can disappear
  (`targetIsGone()` re-validates the goal; the digest categories link to stable index
  pages), and only internal `/…` paths are followed (open-redirect guard).
- Covered by `tests/Service/InAppNotifierTest`, `tests/Service/Delivery/InAppGoalDeliveryTest`
  (deliverable → notify, no-data/error → no ping), and `tests/E2E/NotificationCenterTest`
  (in-process `WebTestCase`: bell badge, inbox listing, mark-read-on-view, empty state,
  click-through marks-read + redirects, deleted-goal target → inbox notice, cross-user 404).
