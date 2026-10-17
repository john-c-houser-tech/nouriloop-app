---
description: "Goal definitions, the setup chat and its cost bounds, starter goals, scheduled check-ins, status semantics, delivery, charts, and goal and notification tiering."
paths:
  - "src/Service/Goal*.php"
  - "src/Service/StarterGoals.php"
  - "src/Service/CheckinAllowance.php"
  - "src/Service/DietaryReference.php"
  - "src/Service/Delivery/**"
  - "src/Service/Chart/**"
  - "src/Controller/Goal*.php"
  - "src/Command/RunGoalCheckinsCommand.php"
  - "src/Entity/NutritionGoal.php"
  - "src/Entity/GoalCheckin.php"
  - "src/Enum/Goal*.php"
  - "src/Dto/GoalDefinition.php"
  - "src/Twig/TextExtension.php"
  - "templates/goals/**"
  - "templates/email/goal_checkin.html.twig"
---

# Nutrition goals — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

## Nutrition goals (conversational setup + scheduled check-ins)
A user defines a measurable nutrition goal — via a **guided chat with Claude** or a
structured form — and NouriLoop evaluates their logged meals against it on a schedule,
delivering a progress check-in. Educational wellness feedback, **not medical advice**
(framed that way throughout, and `GoalSafetyValidator` warns on unsafe targets).

- **Model:** `NutritionGoal` (per-user) holds a structured `GoalDefinition` (JSON
  column, not free text): metric (`GoalMetric` → a `NutritionData` field), direction
  (`GoalDirection` at_most/at_least/**maintain** range via `targetMax`), aggregation,
  target, evaluation-window days, captured baseline. Plus an **editable, versioned**
  `evaluationPromptTemplate` (prompt-as-data), a structured schedule
  (`GoalFrequency`/day/hour/minute — interpreted in the owner's `User.timezone` —
  + materialised `nextRunAt`) and `GoalOutputFormat` (summary/chart/report). Check-in
  **delivery follows the owner's `User.notificationChannel`** — there is **no** per-goal
  channel (migration `Version20260620170000` dropped the old `delivery_channel` column).
  `GoalCheckin` records each run (status, value, narrative, chart path, **tokens**).
  Migration `Version20260611234602`; both tables removed in admin user-delete.
- **Setup conversation** (`GoalConversationController`, `/goals/new` — the primary
  entry; manual form at `/goals/new/manual`): `GoalConversationService` drives Claude
  (system prompt grounded in `DietaryReference` macro DV/DRI values + the user's own
  baseline from `GoalMetricCalculator`) to turn "eat more fiber" into a concrete goal,
  emitting a fenced ```goal-proposal JSON block the controller parses. Transcript +
  pending proposal live in the **session**; only the confirmed goal is persisted, via
  the shared **`GoalFactory`** (used by both the chat and the form).
  **Cost bounds (2026-09-30)** — the chat is free on every tier and each turn resends
  the whole transcript, so cost grows quadratically with length. Three limits, each
  checked **before** the Claude call: messages over
  `GoalConversationController::MAX_MESSAGE_LENGTH` (1000) are refused (`goal.chat.too_long`;
  the input also carries `maxlength`); a conversation stops at `MAX_USER_TURNS` (12 user
  messages) — the input is replaced by a start-over notice (`goal.chat.turn_limit`) rather
  than silently trimming early turns the proposal depends on; and the **`goal_chat`
  rate limiter** allows 20 messages/hour per user (`goal.chat.rate_limited`; higher than the
  one-shot surfaces' 10 because a conversation takes several turns). John's call on the
  numbers. Covered by `tests/E2E/GoalChatLimitsTest`.
- **Starter goals (one-tap templates, new-user Wave 3 2026-07-21):**
  **`App\Service\StarterGoals`** defines four templates (`fiber`/`protein`/`sugar`/
  `calories` — `TEMPLATES` const) that `POST /goals/starter`
  (`GoalController::starter`, CSRF `goal-starter`, whitelist on `template`) turns into
  a real goal in one click: daily-average metric, 7-day window, weekly Sunday-18:00
  summary check-in, name from `goal.starter.name_<tpl>` (translated at creation into
  the user's locale), persisted via the shared `GoalFactory` (so the onboarding goal
  step is marked like every other path). **Targets start at the `DietaryReference` DVs
  and are nudged by the user's trailing 30-day average** where one exists: at-least
  metrics aim ~10% above an already-high baseline **capped at 2× the DV** (fiber 56 g,
  protein 100 g — a garbage baseline must never one-click an absurd goal; Copilot catch
  on PR #165); sugar tightens to ~90% of a below-DV baseline (floored at 25 g);
  calories track 95% of the baseline rounded to 50, clamped to 1,500–3,500 kcal
  (else 2,000). **Safe by construction** — no target
  can trip a `GoalSafetyValidator` warning, so the one-click path has no acknowledge
  step (pinned across baselines by `tests/Service/StarterGoalsTest`). Rendered from
  the shared **`templates/goals/_starter_goals.html.twig`** partial (labels
  deliberately carry no numbers, so they can't drift from the computed target; en_GB
  overrides the fiber labels to "fibre") in two places: the **onboarding goal step**
  and a **"Quick start" strip atop the goal chat**, shown only while the user has no
  goals (`showStarters` from `chat()`; re-renders from message/confirm default it
  off). Covered by `tests/E2E/StarterGoalFlowTest` (strip renders + disappears, tap →
  goal + onboarding mark + redirect to the goal page, overlay carries the buttons,
  junk template rejected).
- **Claude calls** go through the **`AiClient`** seam (moved to its own rules file,
  `ai-client.md`, 2026-10-12): goal setup and evaluation use `complete()`.
- **Scheduled evaluation:** `php bin/console app:run-goal-checkins`
  (`--dry-run`/`--goal`/`--now`), cron **hourly**, restart-safe (due-state is the DB
  `nextRunAt`). Each goal runs in its own try/catch (`checkIn()`), so one goal's
  failure doesn't block the batch; a goal that fails before its flush keeps its
  `nextRunAt` and retries next hour, and any failure makes the run exit `FAILURE`
  (`tests/Command/RunGoalCheckinsCommandTest`). `GoalEvaluationService` computes value/trend/status
  **deterministically in PHP** (`GoalMetricCalculator`); Claude only writes the
  narrative from the filled template (system prompt asks for **plain prose, no
  Markdown**). Empty window → `no_data`, **no Claude call**; a Claude failure falls
  back to a deterministic summary so a check-in is never lost.
- **Narrative rendering:** the AI `summaryText` is shown via the **`inline_md` Twig
  filter** (`App\Twig\TextExtension`) on the goal-checkin email **and** the goal-detail
  page — it escapes the text (so the model can't inject markup) then renders `**bold**`
  → `<strong>` + newlines, so a stray Markdown asterisk never reaches the user as raw
  `**` (the plain-prose system prompt is the source-side guard; this is the display
  guard).
- **Check-in status semantics + the "On track" clarification (2026-07-23):** the status
  machine (`GoalEvaluationService::status`) is three-state on purpose — Achieved = target
  met; **OnTrack = NOT met but the trend is improving, or a first check-in with nothing to
  compare against**; OffTrack = not met and not improving. Two guards keep an under-target
  "On track" from reading as a mislabel (hit in prod: an `at_least` fiber check-in emailed
  "On track" beside 10 g vs a 15 g target, and Claude's narrative called out the
  "contradiction" — undermining the badge): (1) the `narrate()` **system prompt carries a
  status glossary** ("On track" ≠ target met; never question/reinterpret the label or the
  numbers) — deliberately in code, **not** in the per-goal `evaluationPromptTemplate`, so
  it covers every existing goal and user-edited template with no data migration; (2) the
  evaluator persists its trend key on **`GoalCheckin.trend`**
  (`first`/`improving`/`holding`/`slipping`; null = no-data runs and pre-column rows;
  migration `Version20260723150000`), and the check-in email + goal page render a small
  hint under an On-track badge — `goal.status.on_track_hint_improving` (direction-aware
  ICU select: "Below target, trending up" / "Above target, trending down" / band variant)
  or `_first` ("First check-in — nothing to compare against yet"); OnTrack only ever
  pairs with those two trend keys, so no other hint exists. Keys shipped in en + all five
  full catalogs. Covered by the glossary prompt-capture + persisted-trend cases in
  `GoalEvaluationServiceTest` and `tests/E2E/GoalCheckinEmailHintTest` (real-template
  render through the real catalogs, incl. the ICU select and the achieved/legacy-null
  no-hint guards).
- **Delivery:** `GoalDeliveryDispatcher` (`#[AutowireIterator('app.goal_delivery')]`)
  sends over the **owner's `User.notificationChannel`**, isolating per-transport failures.
  **`EmailGoalDelivery`** is the baseline (skips unverified addresses — same gate as
  the digest, see Email ownership verification under Auth; reuses the synchronous `MailerInterface`,
  `templates/email/goal_checkin.html.twig`, chart embedded inline as `cid:chart`).
  **`SmsGoalDelivery`** sends via the same lazy Twilio texter as the notifications digest
  (respects the phone/`smsConsentAt` gate; logs the body + try/catches transport errors).
  **Both email and SMS additionally gate on the Loop+ `checkin_delivery` entitlement**
  (goal tiering, 2026-07-21 — checked per-transport at send time so a lapsed
  subscription quietly drops to in-app only; see Goal & notification tiering below).
  **`InAppGoalDelivery`** drops the check-in into the in-app notification center (see
  **In-app notification center** below) via `InAppNotifier` — it runs for **every**
  channel (in-app is always available), but only for *deliverable* statuses
  (Achieved/On-track/Off-track), so a no-data/error run shows on the goal page without
  pinging the bell.
- **Charts** (`GoalChartRenderer` interface, **`GdGoalChartRenderer`**): pure-GD line
  chart (daily values + dashed target line, or shaded band for maintain), 2×
  supersampled for smooth lines, built-in GD font (no TTF dependency). Generated by the
  evaluator for chart/report goals into `public/uploads/goal-charts/` (gitignored;
  needs the same `www-data` ACL — renderer mkdirs it). The chart file stem is
  **random** (`goal-` + `bin2hex(random_bytes(16))`, set in `GoalEvaluationService`)
  — the old goal-id + timestamp stem was enumerable, and the dir is web-served
  without auth (see the unguessable-filename note under Meal logging). Imagick is a
  one-file swap behind the interface if richer charts are ever needed. **Deleting a
  goal** unlinks its check-ins' chart PNGs after the delete commits (the rows cascade,
  the files didn't — only the account purge cleaned them before 2026-09-30), resolving
  each by file name inside `%goal_charts_dir%` like `EmailGoalDelivery` does.
- **Chart window ≠ evaluation window.** The chart plots a **trend** over
  `max(evaluationWindowDays, GoalEvaluationService::CHART_MIN_DAYS=30)` days — a separate,
  wider `dailyValues` query than the one that computes the headline value — so a daily
  goal (1-day eval window) doesn't render a single useless point. If that wider series
  still has **< 2 points** the chart is **skipped** (no `setChartPath`, email omits it).

### Goal & notification tiering (2026-07-21)
Implements the approved `NouriLoop-Goal-Tiering-Plan.md` (business folder) — born from a
Copilot catch on PR #165 that the old `goals`/`scheduled_checkins` keys were never
enforced. **Goal definition is free for everyone** (it's core to seeing the product's
value; the Wave 3 funnel walks new users into it); the paid ladder is check-in
**cadence/count** and **push delivery**. No schema migration — everything is derived or
lazily migrated.

- **Matrix (since the tier rework, PR 7 — `billing.md`):** Free = weekly check-ins, first
  **6 per account** (lifetime, not monthly), in-app only. Loop and Loop+ = unlimited,
  weekly **or daily** (user's choice), + email delivery of check-ins; **SMS** (the digest
  and check-ins by text) is **Loop+ only**. Digest email + in-app stay free on every tier
  (the evening email is the best Free retention tool; SMS is the only real per-message
  cost). Keys: `unlimited_checkins`/`daily_checkins`/`checkin_delivery` (both paid tiers),
  `sms` (Loop+) — resolved through `SubscriptionService`, so comps and grace periods
  apply. (Before PR 7: Loop = unlimited weekly in-app + digest SMS; daily and delivery
  were Loop+.)
- **`App\Service\CheckinAllowance`** (`FREE_ALLOWANCE = 6`): the Free allowance is
  **derived** — `GoalCheckinRepository::countEvaluatedForUser` counts **evaluated**
  check-ins (Achieved/On-track/Off-track) across all the user's goals; `no_data`/`error`
  runs make no Claude call and deliberately don't count. No column, no cache.
  ⚠️ **Accepted tradeoff:** check-ins cascade-delete with their goal, so
  delete-all-goals (or "Start fresh") resets the count — deliberate (the reset costs
  the user their whole history for pennies of worst-case spend; a real column would
  need the migration the plan declined). Revisit only if abuse appears.
- **Enforcement lives at evaluation/send time** (lazy downgrade makes creation-time-only
  gating unsound): `RunGoalCheckinsCommand` checks per goal **before** `evaluate()` —
  (a) Free owner with the allowance exhausted → skip evaluation, **pause the goal**
  (`isActive=false` + `nextRunAt=null`, the same state as the goal page's Pause button)
  + a one-time in-app notice (`goal.notice.allowance_paused_*`, composed in the owner's
  locale via explicit `$locale` arg — the cron has no request locale); (b) daily goal
  whose owner lacks `daily_checkins` → **persist-migrated to weekly** + one-time notice
  (`goal.notice.daily_to_weekly_*`), then evaluated + rescheduled weekly. Both are
  one-time by construction (paused goals never come due; migrated goals stop matching).
  `findDue()` is unchanged. Nothing is ever deleted — the goal + history stay visible.
- **Resume path is user-driven** (the Stripe webhook deliberately does NOT re-arm goals —
  avoids surprise billing of stale goals): the goal page's existing Pause/Resume toggle.
  For an exhausted Free owner the Resume form is replaced by an **"Upgrade to resume"**
  gold CTA (+ a paused-upsell card), and `GoalController::toggle` has a server-side
  guard (flash `goal.show.resume_locked`) so a stale form can't re-arm a goal the cron
  would instantly re-pause.
- **Creation-time UX (not security):** the manual form renders Daily **disabled** with a
  `goal.form.daily_locked` upsell hint (`canDaily` from `formOptions()`); the chat
  proposal card shows the weekly truth up front with a `goal.chat.daily_note` when a
  daily proposal will be coerced; **`GoalFactory::create` is the server-side backstop**
  (coerces Daily→Weekly for non-entitled users on every creation path). Starter goals
  are already weekly.
- **Goal page UX:** Free users see a subtle `goal.show.allowance` counter ("N of 6
  included check-ins used", amber + pricing link when ≤2 remain — the photo-allowance
  counter pattern); the schedule card's Channel line shows `goal.show.channel_inapp`
  for non-`checkin_delivery` users (honesty — don't imply the channel setting sends
  their check-ins anywhere).
- **Digest SMS gate:** `NotificationService` adds the `sms` entitlement (via `Reachability`) **and a send-time
  `smsConsentAt` check** to the `$sendSms` conditions (Copilot catch on PR #166:
  since the SMS controls are omitted for Free users, a stored SMS channel no longer
  implies TCPA consent — so consent is verified at send time, same as
  `SmsGoalDelivery` always did); email/in-app unchanged. **Settings ▸ Notifications** omits the phone +
  TCPA-consent controls for non-entitled users (`NotificationPreferencesFormType`
  `include_sms` option — fields are *omitted*, not hidden, so an unsubmitted phone
  field can't null a stored number) and shows a `settings.notifications.sms_upsell`
  hint instead; the channel radios stay for everyone (`User.notificationChannel` is one
  shared preference, gated per-feature at send time, never mutated by tier).
- **Marketing copy (same PR, §9 honesty both directions):** pricing Loop card →
  "Unlimited weekly goal check-ins" (reworded 2026-10-08 to "Weekly goal check-ins with no
  limit" — "unlimited" read as unlimited goals) + "Text (SMS) reports"
  (`pricing_page.feature.goal_checkins`/`sms_reports`); Loop+ card → "Daily goal
  check-ins, delivered by email or text" (`feature.daily_checkins`); the inaccurate
  "Email/SMS Reports" row retired (keys `feature.goals`/`checkins`/`reports` removed
  from every catalog); a `pricing_page.free_note` footer states what every account gets.
  `/lp/weight-loss` `goal_tier_note` + `footnote` rewritten to the new truth (the 6 is
  passed as `{limit}` from the `CheckinAllowance` constant via Twig `constant()`, same
  as the pricing footer). `/about` + ToS checked — tier-neutral/price-only, unchanged.
  Post-v2.5.0 fix: the Loop+ card's **Predictive "What If" row dropped its stale
  "(coming soon)"** (+ hourglass icon → check) — the feature shipped 2026-06-26;
  pinned by a no-"coming soon" assertion in `GoalTieringTest`'s pricing case.
- **No grandfathering:** existing Free users past 6 evaluated check-ins pause at their
  next due run with the notice; beta testers hold Loop+ comps and are unaffected (comp
  expiries in 2027 are handled by the same lazy evaluation-time enforcement).
- Covered by `tests/E2E/GoalTieringTest` (in-process WebTestCase + CommandTester: pause
  at exactly 6, no_data doesn't count, entitled unaffected, daily→weekly migrate +
  notice, Loop+ daily untouched, upgrade-resume + the lapsed-comp guard, allowance
  counter, manual-form lock, factory backstop, settings SMS upsell + stored-phone
  preservation, pricing copy), `tests/Service/NotificationServiceSmsGateTest` (digest
  SMS gated for Free, sent for Loop), gating cases in `EmailGoalDeliveryTest` /
  `SmsGoalDeliveryTest`, the new-key matrix in `SubscriptionTierTest`, and the updated
  §9 pin in `LandingPagesTest`.
