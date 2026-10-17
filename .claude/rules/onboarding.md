---
description: "The first-login checklist overlay: steps, state on User, step hooks, the device-aware meal step, and first-run polish."
paths:
  - "src/Service/OnboardingService.php"
  - "src/Controller/OnboardingController.php"
  - "src/Twig/OnboardingExtension.php"
  - "src/Dto/OnboardingProgress.php"
  - "templates/onboarding/**"
  - "templates/dashboard/**"
  - "tests/E2E/OnboardingTest.php"
  - "tests/Entity/UserOnboardingTest.php"
---

# Onboarding flow — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

## Onboarding flow (first-login overlay)
A post-first-login checklist that nudges a new user through the core loop: **3 required
steps** (log a meal · set a goal · turn on reminders) **+ 1 optional last step**
(connect health data — UI tag "Optional — last step", was "Extra credit" until
new-user Wave 3). Floating card bottom-right on desktop; a near-full-width bottom
card below `md` (768px) with "Skip setup" replacing the ×.

- **No kernel listener / no login redirect.** The overlay is a Twig include rendered in
  `base.html.twig` (next to the floating help button) gated on
  `app.user and app.user.needsOnboarding()`, so it rides along on **every** authenticated
  page — including the step CTAs' targets (`/meal/log`, `/goals/new`,
  `/health-metrics/import`). A force-redirect listener was rejected because it would bounce
  the user back before they could complete a step. Steps are marked **server-side** at the
  point the action actually happens; the overlay re-renders from current state on the next
  page load (no JS step endpoint, no polling — inline vanilla JS only, like the rest of the
  app, since Stimulus isn't loaded).
- **State on `User`** (migration `Version20260628153836`): `onboarding_completed_at`
  (stamped once all 3 **required** steps are done; null = active), `onboarding_dismissed`
  (permanent hide, overrides completion), and per-step `onboarding_{meal,goal,notifications,health}_at`.
  Helpers: **`User::needsOnboarding()`** (`!dismissed && !allOnboardingStepsResolved()` —
  the overlay shows until every step, including the optional one, is resolved or the user
  dismisses), **`User::allOnboardingStepsResolved()`** (all four timestamps set — health
  counts as resolved once **done or skipped**), and **`User::markOnboardingStep($step, $at)`**
  (idempotent via `??=`; `onboardingCompletedAt` is a separate "finished the 3 required"
  milestone — set only when the 3 required timestamps are all present, **health never sets
  it**; unknown `$step` throws, an intentional guard). All columns are on the `user` row, so
  the admin user-delete needs no change.
- **`OnboardingService`** is the single write/read point: `markStep` (no-op once the user
  no longer `needsOnboarding()`, then flush), `progress(User): OnboardingProgress`,
  `dismiss`/`enable`. **`OnboardingProgress`** DTO carries the per-step booleans +
  `allRequiredDone()`/`requiredRemaining()`/`activeStep()` (first incomplete in order
  meal→goal→notifications→health). The overlay reads it via the
  **`onboarding_progress()` Twig fn** (`App\Twig\OnboardingExtension`; null when logged out;
  computed from already-hydrated `User` fields, no query).
- **Step hooks** (each calls `OnboardingService::markStep`): meal →
  `MealController::saveFromState` after the flush; goal → `GoalFactory::create` after persist
  (the **single** factory method — `GoalFactory` gained an `OnboardingService` dep); health →
  `HealthMetricsController::import` success path; notifications → the dedicated
  `OnboardingController::notifications` route.
- **`OnboardingController`** (`^/` → `ROLE_USER`, no new security rule): `GET /onboarding`
  (stable deep-link → redirect to dashboard), `POST /onboarding/dismiss` (CSRF
  `onboarding-dismiss`), `POST /onboarding/notifications` (CSRF `onboarding-notifications`
  — confirms timezone + the four **email** notify toggles; SMS opt-in keeps its TCPA consent
  flow on the full Settings page; marks the step seen even with everything off),
  `POST /onboarding/visibility` (CSRF `onboarding-visibility` — the Settings ▸ Notifications
  "Show the setup checklist" toggle; shown while `not allOnboardingStepsResolved()`, so a
  dismissed user can re-enable), and `POST /onboarding/step/health` (CSRF `onboarding-step` —
  the optional health card's buttons; `then=connect` continues to the **CGM setup wizard**
  (`/connect-cgm` — see Connect your CGM wizard), `then=import` to the file importer, no
  `then` = "Not now, finish setup"). The onboarding POSTs are ordinary same-origin form
  submits and pass the app's same-origin CSRF check with no special handling (see Forms &
  CSRF).
- **Templates** in `templates/onboarding/`: `_overlay.html.twig` (wrapper + 4 progress pips —
  3 teal required + 1 gold optional, dashed until earned — dismiss + the single
  active-step include) and one partial per step. **One step shows at a time**, the first
  incomplete of meal → goal → notifications → health. **Reworked in new-user Wave 3
  (2026-07-21):** the **goal step embeds the one-tap starter-goal buttons**
  (`goals/_starter_goals.html.twig`, shared with the chat page — see Nutrition goals ▸
  Starter goals) with the chat/manual paths behind a link, so the user's second-ever action
  doesn't have to be an open-ended AI chat; the **health step is framed as a question**
  ("Do you wear a glucose sensor (Lingo or Stelo)?", tag renamed "Optional — last step",
  keys `onboarding.optional_tag` + `onboarding.health.question/yes_connect/no_import/
  not_now`) — Yes → CGM wizard (primary), No → Apple Health import, and an **equal-weight
  "Not now, finish setup" button** (btn-outline-secondary, not a muted link) resolves it.
- **Device-aware meal step (2026-08-01).** "Snap a photo" is a real barrier on a
  desktop/laptop — the user has to shoot the meal on a phone and then get the file
  onto the computer — so `_step_meal.html.twig` ships **two server-rendered variants**
  (`[data-meal-variant="touch"]` visible, `[data-meal-variant="pointer"]` `d-none`) and
  a few lines of inline JS swap them only when **BOTH gates agree**:
  `matchMedia('(pointer: fine)')` matches **and** `innerWidth >= 768` (the log page's
  `d-md-none` breakpoint). ⚠️ The pointer test is **positive on purpose** — `pointer`
  has a third value, `none` (keyboard-only / TV-style devices), so `!(pointer: coarse)`
  would hand the desktop variant to exactly the ambiguous devices the rule protects
  (Copilot catch on PR #188). Deliberately asymmetric — showing the photo CTA to a desktop
  user is a mild annoyance, but showing "describe it in words" to someone holding a
  camera hides the easy path behind the hard one, so anything ambiguous falls back to
  photo. (The width gate also keeps a resized desktop browser — which still reports a
  fine pointer — from previewing the wrong variant.)
  The pointer variant leads with the **photo-free path** (`onboarding.meal.desktop_lead`/
  `desktop_cta` → `/meal/log?describe=1`, which server-renders the log page's description
  box open — `describeOpen` in `meal/log.html.twig`, the coupon-disclosure idiom) and
  offers the **device hand-off second** (`phone_cta` → `POST /login/link/to-phone`, below).
  ⚠️ Three deliberate choices: pointer type leads the test and **no camera probe is
  done** (laptops have webcams, and a webcam pointed at a plate is exactly the awkward
  case — the presence of a camera isn't the signal, the presence of a *pocketable* one
  is); the touch copy is the **no-JS fallback**; and "go to your phone" is never the
  **only** CTA — a step whose sole action sends the user elsewhere is a step they
  abandon.
  Covered by `OnboardingTest` (both variants in the markup, `?describe=1` opens the
  field).
- **"Continue on my phone" — `POST /login/link/to-phone`** (`LoginLinkController::toPhone`,
  CSRF `login-link-phone`): a signed-in user mails a **standard 15-minute magic link to
  their OWN address** so they can pick the session up on a device with a usable camera.
  Reuses `sendLoginLink()` and `email/login_link.html.twig` — no new mail template, no new
  lifetime. ⚠️ **`^/login` is PUBLIC_ACCESS**, so the action asserts `ROLE_USER` itself;
  the firewall will not. The recipient is always `$this->getUser()`'s address and is never
  read from the request — that's what makes exposing it safe (nothing to enumerate, no
  arbitrary address to mail). Rate-limited on the existing `login_link` limiter under a
  **separate `login-link-user-<id>` key namespace** so one household's devices don't share
  the per-IP allowance. `sendLoginLink()` now **returns bool**: the anonymous path still
  ignores it (a transport failure there would reveal the account exists), while this
  caller reports failure honestly — a silent "sent" would leave the user waiting on an
  email that never arrives. Covered by `LoginLinkTest` (own-address send, forged token
  sends nothing, anonymous POST refused).
- **First-run polish (new-user Wave 4, 2026-07-22 — E15–E18 of the plan):**
  - **Dashboard ghost previews (E15):** while `app.user.needsOnboarding()`, the
    dashboard renders static dashed-border "Preview" teaser cards for the hidden
    differentiators — the inflammation card (only when `inflammationToday` is null)
    and the glucose card (only when `not hasGlucose`, with a Connect-your-CGM CTA to
    `/connect-cgm`). Pure markup in `templates/dashboard/index.html.twig`
    (`dashboard.ghost.*` keys), no data queries; they vanish the moment the real card
    has data or onboarding resolves.
  - **First-meal success moment (E16):** `MealController::saveFromState` detects the
    very first meal (`needsOnboarding() && onboardingMealAt === null`, checked
    **before** `markStep` stamps it) and sets a one-shot `first_meal` flash;
    `templates/meal/show.html.twig` renders it as a celebratory callout with a "See
    today's score" CTA to the dashboard (`meal.show.first_meal_*`) — the payoff for
    the hardest step is no longer just the generic saved flash. The flash value is
    `today`/`backdated` (compared in the user's local frame): a **backdated** first
    meal (EXIF default / edited date) puts nothing on today's dashboard, so it gets
    date-neutral copy + CTA (`first_meal_{text,cta}_backdated` — Copilot catch on
    PR #169).
  - **Notifications step preset (E17):** the onboarding notifications mini-form leads
    with a one-tap **"Recommended: just the evening reminder"** submit
    (`name="preset" value="recommended"` — `OnboardingController::notifications`
    enables only `notifyNoMeals`, ignoring/clearing the checkboxes) and the
    stale/calorie/supplement toggles gained small "matters once …" hints
    (`onboarding.notifications.{recommended_btn,recommended_hint,or_pick,*_hint}`).
    The toggle labels themselves were already outcome-language from the i18n
    extraction, so E17's label-rewrite half was a no-op.
  - **Empty-state dead ends (E18):** the Favorites empty card gained a "Log a meal to
    get started" CTA (`favorite.empty_cta`); the Health Metrics empty block now holds
    **both** on-ramps (Import data + Connect your CGM, `health.connect_cgm_cta`); the
    Supplements no-meals state swapped the green check-circle (reads as "done") for a
    neutral `bi-clipboard-data` icon. Plus a real fix underneath:
    **`SupplementRecommendationService::computeGaps` treats an all-zero
    `$foodAverages` map as no-data** — `DailyIntake::average()` returns a full
    map of 0.0s for an empty window, so a brand-new user previously saw "18 nutrient
    gaps detected" instead of the no-meals empty state (every caller — supplements
    page, Trends micros tile, digest notice, Predict — benefits; nutrients are
    non-negative, so all-zero can only mean no data). ⚠️ An empty gap list is
    therefore ambiguous ("no gaps" vs "no data" — Copilot catch on PR #169), so
    **`hasNutritionData(averageIntake)`** is the companion signal: the supplements
    page uses it (the controller fetches the average intake itself, the
    TrendsController idiom) to render a third state — neutral-icon
    `supplement.no_data_window` — for a user whose meals all predate the selected
    window (the green "no gaps" check only appears when real data backs it), and
    **Predict omits its gap-count before/after row when the baseline has no
    nutrition data** (0 → N would misread as the scenario making nutrition worse).
    The Trends micros tile needs nothing: it already mutes on empty calorie totals.
- Covered by `tests/Entity/UserOnboardingTest` (DB-less state machine — completion,
  idempotence, health-doesn't-complete, dismiss, unknown-step throw),
  `tests/E2E/OnboardingTest` (in-process `WebTestCase`: fresh user sees the overlay on the
  meal step, completing the 3 required advances to the optional health question, "Not now"
  closes the overlay, the notifications mini-form persists toggles + advances, the
  Recommended preset enables only the evening nudge + overrides pre-set toggles, ghost
  previews render during onboarding and disappear once resolved, dismiss +
  Settings re-enable), the overlay starter-button case in
  `tests/E2E/StarterGoalFlowTest`, the first-meal-callout-exactly-once case in
  `tests/E2E/MealLogPathsTest`, and `tests/E2E/EmptyStateCtaTest` (E18: favorites CTA,
  health-metrics dual CTAs, supplements neutral icon — the last also pinning the
  computeGaps no-data guard).
