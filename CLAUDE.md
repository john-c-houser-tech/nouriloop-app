# NouriLoop — Developer Notes (CLAUDE.md)

> **⚠️ Keep this file current — it is the project's source of truth and change/roadmap
> record (Claude Cowork and other agents read it to track the system).** Every
> behavioral or structural change — feature, entity/column, route, service, config,
> migration, deploy/infra — **must** update the relevant section here **in the same PR**
> as the change. "Did I update CLAUDE.md?" is part of done, alongside tests and cs-check.

Symfony web app for nutrition tracking: food-photo analysis (Claude vision),
daily logs, trend charts, supplement recommendations, health-metric imports, and
scheduled email & SMS notifications. Deployed to a production VPS
(**https://nouriloop.com**); multi-user with full auth.

> **Status: publicly launched — `v1.0.0`, soft-launch 2026-06-28.** Registration is
> **open to the public** (Free tier; beta codes still grant Loop+ — see Registration),
> **Stripe billing is live in production** (real keys + products/prices/webhook configured
> in the server `.env.local`; see Subscriptions / billing), and the footer shows the
> release version. Growing users slowly / gathering feedback before marketing.

## Keeping docs in sync

This file is the canonical source for NouriLoop's implementation state — other planning docs
(`FEATURES.md`, the Task Tracker, the Marketing Plan, the Roadmap) link back here rather than
re-describing shipped features in their own words. Full rules + the cross-folder doc map live
in the private business folder's `DOCS-INDEX.md` (added 2026-07-12, after a sync
review found several planning docs had drifted). Short version: update this file in the same
PR as any behavioral/structural/infra change (already stated above), and if you add a status
claim to `FEATURES.md` or any business-folder doc, make sure it agrees with what's here.

## Stack
- **Framework:** Symfony 7.4 (PHP ≥ 8.2), Doctrine ORM, Twig, Bootstrap 5 (CDN).
- **DB:** MariaDB (local). Migrations in `migrations/`.
- **Standards:** PSR-12, enforced by PHP_CodeSniffer (`phpcs.xml.dist`). Every PHP
  file uses `declare(strict_types=1)`.

## Common commands
- Run dev server: `symfony serve` (or `php -S 127.0.0.1:8000 public/index.php`)
- Migrations: `php bin/console doctrine:migrations:migrate`
- Generate migration from entity diff: `php bin/console doctrine:migrations:diff`
- Lint PSR-12: `composer cs-check` · auto-fix: `composer cs-fix`
- Lint container / routes: `php bin/console lint:container` · `debug:router`
- Send notifications: `php bin/console app:send-notifications` (`--dry-run`, `--user=<email>`)
- Run goal check-ins: `php bin/console app:run-goal-checkins` (`--dry-run`, `--goal=<id>`)
- Tests: `vendor/bin/phpunit` (unit/functional) · `vendor/bin/phpunit -c phpunit.e2e.xml.dist` (browser E2E) — see **Testing & CI**

**Local setup.** Any PHP ≥ 8.2 with `intl` and `imagick`, MariaDB, and a mail catcher
(Mailpit on `127.0.0.1:1025`) will run the app; put `APP_SECRET`, `DATABASE_URL` and
(optionally) `ANTHROPIC_API_KEY` in an untracked `.env.local`. The maintainer's own
machines are described in `docs/ops/local-machines.md` (kept out of the public export).

## Auth
- Form login (`SecurityController`), **open public registration** with an **optional**
  beta code (`RegistrationController`, see Registration below — soft-launched 2026-06-28;
  was invitation-only before). **Remember-me is always on** (`always_remember_me: true`,
  **180-day** lifetime; the login page has no checkbox — every sign-in gets the long
  cookie, logout clears it). Password policy: **min 8 chars + a breached-password check**
  (`NotCompromisedPassword` on registration and reset, aligned): the k-anonymized
  HaveIBeenPwned API with `skipOnError: true`, so an HIBP outage fails open rather than
  blocking sign-up; **disabled in the `test` env** (`validator.yaml` `when@test`), so
  tests/CI make no network calls and weak fixture passwords keep working. **No
  composition rules on purpose** (NIST 800-63B: length + breach check beat forced
  symbols); the registration/reset forms instead show a passphrase tip ("three or four
  random words"). All password fields get a **show/hide toggle**
  (`templates/_password_toggle.html.twig`, inline vanilla JS included from the
  login/register/reset `javascripts` blocks — decorates every `input[type=password]`
  with an eye button; added for the older-skewing audience). Covered by
  `tests/E2E/PasswordUxTest` (in-process `WebTestCase`: no checkbox + ~180-day
  `REMEMBERME` cookie on plain form login, toggle renders, passphrase tip).
  Failed logins are rate-limited (`firewalls.main.login_throttling`, 5 attempts per
  username+IP; needs `symfony/rate-limiter`).
- **Passwordless sign-in (magic links)** — "Email me a sign-in link" on the login page
  (`LoginLinkController`, Symfony's built-in `login_link` authenticator on the `main`
  firewall; no extra package). `GET|POST /login/link` is the request form (same
  spam-defense recipe as the feedback form: `LoginLinkRequestFormType`, honeypot `website`
  field, Turnstile, and a **`login_link` rate limiter** — 5/hour per client IP,
  `config/packages/rate_limiter.yaml`); the outcome is **always** the same
  `/login/link/sent` page whether or not the account exists (**no enumeration** — same
  posture as password reset; a mail-transport failure is also swallowed + `warning`-logged,
  since erroring would reveal the lookup succeeded). Links are **single-use**
  (`max_uses: 1`, tracked in an auto-provisioned cache pool) and live **15 minutes**
  (`lifetime: 900`); `signature_properties: ['id', 'password']` means a password
  change/reset invalidates outstanding links. **Scanner protection:** the emailed URL is
  the check route (`/login/check`), but with **`check_post_only: true`** a GET renders a
  "Finish signing in" confirm page (`login_link_confirm.html.twig`, button POSTs back to
  the same URL, query string included) and only the POST authenticates — email clients /
  corporate scanners prefetch GETs but don't submit forms, so they can't consume the
  link. ⚠️ The confirm page renders **even for an already-logged-in visitor** (fixed
  2026-07-05, hit in prod: register → password login → open the welcome email's confirm
  link — an early logged-in→dashboard redirect silently skipped the consumption that
  stamps email verification; the POST just re-authenticates, which is harmless). The
  authenticator carries a `RememberMeBadge`, so link sign-ins get the 180-day
  always-on cookie like form logins. Disabled accounts get no email (checked pre-send)
  **and** are blocked at consume time by the firewall `UserChecker`. Security framing:
  email access already resets the password, so magic links add convenience without
  lowering the auth bar. Covered by `tests/E2E/LoginLinkTest` (in-process `WebTestCase`:
  full round trip incl. remember-me cookie + the logout-target-path regression below,
  single-use, no-enumeration, honeypot, disabled account, per-IP 429, bare `/login/check`
  redirect).
- **Email ownership verification (soft-gate)** — `User.email_verified_at` (migration
  `Version20260705194401`, which **backfilled all pre-existing users as verified** so
  their scheduled mail didn't stop). Null = unverified: the app stays **fully usable**;
  the only hard gate is **scheduled email sends** — `NotificationService` skips the
  digest's email channel and `EmailGoalDelivery` skips check-in emails for unverified
  users (SMS + in-app unaffected; transactional mail — reset/magic-link/welcome — is
  exempt since it IS the verification mechanism). **Proof = any inbox-control event**,
  stamped via `App\Service\EmailVerifier::markVerified` (idempotent): a **magic-link
  sign-in** (`LoginLinkVerificationListener` on `LoginSuccessEvent` — unwraps the dev
  profiler's `TraceableAuthenticator`), a **completed password reset**
  (`ResetPasswordController`), or the **welcome email's "Confirm your email & sign in"
  CTA** — a `createLoginLink($user, lifetime: 86400)` 24 h login link (note: the
  single-use cache TTL is the firewall's 900 s, so within its 24 h that link is
  effectively re-usable by the inbox owner — same power as a reset email, accepted).
  Unverified users see a slim top banner (`_email_verification_banner.html.twig`,
  deletion-banner pattern) with **Resend** (`POST /verify-email/resend`,
  `EmailVerificationController`, CSRF `verify-email-resend`, `email_verification`
  limiter 3/hour per user, template `email/verify_email.html.twig`); it clears itself
  on any proof, no dismiss state. `/admin/users` shows a subtle **Unverified** badge.
  **Pre-hijacking is narrowed, not eliminated** (hard activation gating was
  deliberately skipped for funnel reasons — revisit only if abuse appears).
  ⚠️ Test-fixture rule: seeded users in tests should `setEmailVerifiedAt(...)` (like
  the grandfathered population) unless the test exercises the unverified state — the
  banner's form/`_token` is otherwise picked up by naive crawler selectors
  (`E2ETestCase::seedUser` does this; bitten by Predict/Tone/NotificationCenter tests).
  Covered by `tests/E2E/EmailVerificationTest` (confirm-link round trip incl. banner
  lifecycle, reset-verifies, resend + 429, digest email withheld but in-app delivered)
  and `tests/Service/Delivery/EmailGoalDeliveryTest` (check-in email gate).
- Access control in `config/packages/security.yaml`: `^/login`, `^/logout`,
  `^/register`, `^/reset-password`, `^/privacy`, `^/about` are
  `PUBLIC_ACCESS`; `^/admin` requires `ROLE_ADMIN`; everything else requires
  `ROLE_USER`. ⚠️ **`^/logout` must stay `PUBLIC_ACCESS`** (fixed 2026-07-05, found
  live-testing magic links): when it required `ROLE_USER`, an anonymous GET `/logout`
  (stale bookmark, back button) was access-denied and the firewall **saved `/logout` as
  the session's post-login target path** — the next successful sign-in (form *or* link)
  then redirected through `/logout` and instantly logged the user back out. The logout
  listener no-ops without a token, so the public rule is harmless.
- Disabled accounts (`User.is_active = 0`) are blocked by `App\Security\UserChecker`,
  wired on **both** the `main` (web) **and** `api` (bearer-token) firewalls — so
  revoking a user also cuts off their API tokens, not just web login.
- **Session cookie is app-named:** `framework.session.name: NOURILOOP_SESSID`
  (`config/packages/framework.yaml`, added 2026-07-14). Browser cookies ignore ports, so on
  `localhost` this app (`:8000`) and Campaigner's dev server (`:8010`) shared the default
  `PHPSESSID` cookie **and** the same PHP session save path (`session.save_path` — unset in
  both apps' PHP configs, so each falls back to the same system temp dir) — logging into
  Campaigner (whose single operator is a Symfony `InMemoryUser`) and then visiting NouriLoop
  made the Doctrine user provider try to refresh that `InMemoryUser` and threw a
  `RuntimeException` 500 on every route until the session was replaced. Each app now names
  its own cookie (Campaigner sets `CAMPAIGNER_SESSID`). Prod effect of the rename: a
  one-time drop of existing sessions at deploy; the always-on remember-me cookie
  (`REMEMBERME`, unaffected) signs users straight back in.
- **Public marketing page:** `/about` (`AboutController`, `templates/about/index.html.twig`)
  is a no-login feature overview, linked from the login page ("Learn more about NouriLoop").
- **Login-page layout — two audience zones (2026-07-14):** everything for a *returning*
  user sits together in the teal family — email, password (with "Forgot password?" inline
  on the label row, not a standalone line), the solid-teal Sign In, then "or" + the
  **`btn-outline-success`** magic-link button (was outline-secondary) — and the *new-user*
  CTA is a visually distinct tinted box (`border rounded-3 bg-light`): "New to NouriLoop?"
  + a full-width **gold "Sign up for a free account"** button + a "No credit card needed."
  note. The returning-user panel's heading is a neutral **"Sign in"** (`auth.login.heading`,
  2026-10-08 brand review) — it was "Welcome back / Sign in to continue.", which greeted
  every first-time visitor arriving from an ad as a returning user. Gold = a new **`.btn-warning`** override in `base.html.twig` (palette `--gold` bg,
  slate text — same combo as `.btn-outline-warning`'s hover state); it's global, so the
  deletion banner's "Cancel deletion" button moved from Bootstrap's default yellow to
  palette gold too. Replaced a small text link that new visitors could read as invite-only.
- **Welcome email:** on a successful registration `RegistrationController::sendWelcomeEmail`
  sends the new user a "Welcome to NouriLoop" message (`templates/email/welcome.html.twig`,
  a personal note from John; greets `user.displayName ?? user.email`). The letter runs
  **uninterrupted**; a visually separated **"P.S." block below the sign-off** carries the
  **"Confirm your email & sign in"** button — a 24 h login link that verifies the address
  on consumption (see Email ownership verification under Auth; placement is John's call
  2026-07-05 — skimmers are backstopped by the in-app banner). Best-effort —
  wrapped in try/catch + `warning` log (channel `app`), so a mail failure never blocks
  registration (same pattern as the admin new-user notice). Uses the global
  `MAILER_FROM`; no per-message `->from()`. Covered by `tests/E2E/WelcomeEmailTest`
  (in-process `WebTestCase` asserting `assertEmailCount`).

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

## Help center (`/help`)
Public, plain-language help — **Phases 1 + 2 + 3** of the help-system plan (see the external
`NouriLoop-Help-System-Plan.md`).

- **`HelpController`** (`^/help` is `PUBLIC_ACCESS`): `/help` (index) + `/help/{topic}`.
  **`HelpController::TOPICS`** is the single source of truth — a `slug → {title, summary}`
  registry of the 12 feature topics (dashboard, log-meal, favorites, **import-recipe**,
  health-metrics, supplements, inflammation, symptoms, glucose, **connect-cgm**, goals,
  settings); `show()` 404s on an unknown slug. The registry will also feed the Phase-2 NL assistant manifest.
  (Adding a topic auto-propagates to the sitemap, the JSON-LD `ItemList`, the NL assistant's
  grounding corpus, and the TOPICS-driven loops in `HelpPagesTest`/`SeoTest`/`SeoJsonLdTest`.)
- **Templates:** `templates/help/index.html.twig` (topic grid) + one
  `templates/help/<slug>.html.twig` per topic, all extending `help/_layout.html.twig`
  (back-link + a `help_title`/`help_body`/`help_related` block structure). Each topic
  **leads with a screenshot** (a framed `<figure>` at the top of `help_body`, referencing
  `public/images/help/<slug>.png` via `asset()`). The **symptoms** topic additionally embeds
  two supplementary screenshots further down `help_body`: `symptoms-chart.png` (an example
  per-symptom relationship panel from Trends) and `symptoms-significance.png` (that panel's
  "Significant diet/health correlations" callouts, backing a plain-language explainer of what
  "statistically significant" means — n ≥ 7 matched days, p < 0.05, and how to read the `r`
  value — see Screenshots below).
- **Screenshots (`App\Command\CaptureHelpScreenshotsCommand`, `app:capture-help-screenshots`):**
  regenerates 15 PNGs into `public/images/help/` (committed to git — **not** under the
  gitignored `public/uploads/`): the 12 one-per-topic shots plus `symptoms-chart.png` +
  `symptoms-significance.png` + `import-recipe-reference.png` (the favorites page with
  the seeded recipe favorite's "Ingredients & steps" collapse opened via
  `executeScript` click — the import-recipe topic's "cooking from it later" shot).
  ⚠️ Since the demo user carries a **permanent Loop+ comp** (see Demo data below,
  2026-07-22), the `glucose.png` shot shows the real Glucose Insights page, no longer
  the Free-tier locked upsell — the right image for help (the topic explains the
  gating in prose). (The `connect-cgm` topic's lead shot is the wizard page itself;
  the topic's/wizard's **hand-captured iPhone screenshots of Health Auto Export**
  (`connect-cgm-hae-*.png`) and the App Store QR (`connect-cgm-appstore-qr.png`) are **not**
  regenerated by the command — refresh them manually if HAE's UI changes.) Run it in the **test** env so the captures are clean (Claude
  mocked, dev web-debug toolbar off): it seeds the demo user into `nutrition_test`, boots a
  Panther web server + headless Chrome (chromedriver from `./drivers`, same as the E2E suite),
  logs in as the demo user, and shoots one route per topic (the inflammation topic → `/trends`;
  the settings topic → the **`/settings` hub** — retargeted 2026-07-22 when the topic was
  refreshed to cover every hub category (Preferences incl. language/GLP-1/tone, tiering-honest
  SMS copy, subscription, account deletion); others map 1:1). 2× device-scale for retina
  crispness. **The two `symptoms-*` shots** are
  extra Trends captures, separate from the 1:1 topic map: the per-symptom Diet/Health
  relationship panel is a `.insight-panel` that's `d-none` until its tile is tapped, so the
  plain `/trends` GET behind the `inflammation` shot never reveals it — these shots instead
  look up the demo user's **joint stiffness** symptom type id (fresh post-seed; Doctrine ids
  aren't stable across re-seeds since the seeder deletes rather than truncates) and deep-link
  to `/trends#panel=symptom-<id>`, the hash the page's own JS already honours on load;
  `symptoms-significance.png` additionally scrolls the panel's first `.bg-light` correlations
  box (`executeScript` + `scrollIntoView`) to the top before shooting. Needs the test DB
  migrated + a Chrome/chromedriver pair, like the Panther suite:
  `APP_ENV=test php bin/console app:capture-help-screenshots`.
- **Demo data (`App\Service\DemoDataSeeder` + `app:seed-demo-user`):** (re)builds one
  realistic demo user (`demo@nouriloop.com` / `demopass123`) with ~60 days **through today**
  across **every** feature — meals (template-based, with a clean/indulgent rhythm that drives a
  varied inflammation chart), favorites, health metrics incl. **dense CGM glucose** for per-meal
  curves, supplements, correlated symptoms (joint pain + energy same-day, **joint stiffness**
  lagged one day off yesterday's indulgence so its panel exercises the next-day correlation
  too — also the symptom the help-center screenshot above uses), and a goal with check-in
  history. The day-loops run down to `$d = 0` (today) so the Dashboard "Today" view + today's
  glucose card render populated in screenshots and the review account. The user also gets
  **`onboardingDismissed = true`** (the onboarding overlay postdates the original demo fixture;
  without this the floating "Get set up" card covers every screenshot), a **permanent Loop+
  comp** (decision 2026-07-22, pending since v2.3.0: the demo account is the standing review
  login and the screenshot subject — reviewers/captures should see the product, not locked
  upsells or an exhausted Free allowance; also removes the ad-hoc comp the lp-screenshot
  recipe needed and keeps the demo goal clear of the Free check-in pause), an
  **imported-recipe favorite** ("Grandma's Lentil Soup" — badge + batch ingredients +
  steps for the favorites/import-recipe shots; the 4th favorite, most recently used so
  it sorts first and the capture command's collapse-click hits it), and **one recently-used
  API token** ("Health Auto Export", `lastUsedAt` −5 min) so the CGM setup wizard's
  verification ladder screenshots fully green — the token's plaintext is random and discarded
  (the demo account doubles as a review login, so a known plaintext would be a real
  credential; the only non-deterministic bytes, nothing visible varies). Deterministic (a seeded
  local `Random\Randomizer`, not the global RNG) and **idempotent** (clears the demo user's rows
  first in one transaction, FK-safe via portable `IN (SELECT …)` subqueries). It's the reusable
  fixture behind the screenshots **and** the standing local review/test account (replaces the
  ad-hoc throwaway seeders). Covered by `tests/E2E/DemoDataSeederTest`. ⚠️ **`app:seed-demo-user`
  refuses to run in `prod`** (2026-09-30): the account's password is published (here and
  in the seeder) and it carries a permanent Loop+ comp, so seeding it there would mint a
  real, known credential. Dev and test stay allowed — unlike `app:capture-help-screenshots`,
  which is test-only (`tests/Command/SeedDemoUserCommandTest`).
- **Contextual links:** `help_link('topic', 'anchor')` — a Twig **function**
  (`App\Twig\HelpExtension`, not a macro, so it works in any template with no import) that
  renders a small `?` icon linking to the topic. Wired into the feature page headers (meal log,
  goals, health metrics, supplements, symptoms, glucose insights, favorites, trends →
  inflammation, settings ▸ notifications); more can be added trivially. A **Help** entry is in
  the nav **More** dropdown.
- Covered by `tests/E2E/HelpPagesTest` (in-process `WebTestCase`: index + every topic
  render anonymously, unknown topic 404s).

### Natural-language assistant (Phase 2, `POST /help/ask`)
A free-text Q&A box on the `/help` index that answers questions grounded **only** in the
help content, plus a floating `?` button on logged-in pages (a link to `/help#ask`).

- **`HelpAssistantService::ask(question, ?User)` → `HelpAnswer` DTO** (`answer` prose +
  1–3 `{title, url, reason}` pages). `getRelevantPages()` ranks topics by **keyword
  overlap** of the question against each topic's title + summary + body text + the
  per-locale `help_assistant.keywords.<slug>` catalog lines (no embeddings at ~10
  topics); the tokenizer is Unicode-safe with Latin accent-folding, so retrieval works
  for non-English questions too (see i18n Phase 3). `buildPrompt()` sends Claude the full `TOPICS` manifest +
  the plain text of the top **3** matched pages so it can't answer about things the app
  doesn't do. Model is **haiku** (`claude-haiku-4-5-20251001`) — cheap/fast; passed via a
  new optional `$model` arg on **`ClaudeClient::complete()`** (default stays sonnet for
  goals). `ClaudeClient` is injected **lazily**, so a help page that never asks doesn't
  resolve the API key. A Claude failure (or empty/garbled JSON) **degrades to a
  deterministic fallback** that just lists the matched pages — the widget always shows
  something. Returned page links are **validated against the real help routes**, so a
  hallucinated URL can't reach the user.
- **`HelpContentExtractor`** renders each topic's `help_body` Twig block and strips the
  markup to plain text (memoized per request) — one source of truth, no second copy of the
  help prose to keep in sync. (The leading screenshot `<figure>` falls away with its tag.)
- **`HelpController::ask()`** is declared **before** `show()` so the static `/ask` wins over
  `/{topic}`. **Rate-limited** (`config/packages/rate_limiter.yaml`): `help_assistant`
  10/hour per signed-in user, `help_assistant_anon` 5/hour per IP (injected by
  `#[Target('help_assistant')]` / `#[Target('help_assistant_anon')]`; a blank question is
  rejected **before** the limiter, so it doesn't spend a slot — 2026-09-30). Requires an
  `X-Requested-With` header (cheap same-origin guard against cross-site token-budget abuse).
  Returns `{answer, answerHtml, pages}` — `answerHtml` is the answer through the **`inline_md`**
  filter (the goal-checkin display guard, so a stray `**` never reaches the user).
- **Widget JS is inline** in `templates/help/index.html.twig`'s `javascripts` block (plain
  vanilla `<script>`, like `meal/log.html.twig`) — **this app does not render `importmap()`
  / load Stimulus**, so interactive JS is inline, not Stimulus controllers. It POSTs the
  question, shows a typing indicator, and appends the last ≤5 Q&A pairs (no server history).
- **Claude is mocked in tests** (`FakeAnthropicResponder` returns the answer-JSON shape when
  the system prompt names the "NouriLoop help assistant"). Covered by
  `tests/E2E/HelpAssistantTest` (answer + validated links, same-origin guard, validation,
  anon 429 rate limit, keyword retrieval incl. es/de/fr/ru/hi queries + accent folding +
  unsupported-script safety).

### Predictive Help (Phase 3, `GET/POST /insights/predict`, Loop+)
A "what if" scenario engine that runs NouriLoop's own deterministic scoring functions
**entirely in memory** against a user's 30-day baseline — no scenario DB, no data copy.
Gated by `SubscriptionService::hasFeature($user, 'predictive_help')` (Loop+ only);
non-subscribers are redirected to `/pricing`. The POST is **CSRF-protected**
(`isCsrfTokenValid('predict-run', …)`, checked before the rate limit / Claude call) and
**rate-limited**: `predictive_insight` 10/hour per user (`config/packages/rate_limiter.yaml`).

- **Scenario types** (`App\Enum\ScenarioType`): `favorite_daily` (eat a saved favorite
  every day), `supplement_add`, `supplement_remove`, `free_text` (described change,
  Claude reasons qualitatively). Window: 1, 2, or 4 weeks. Dimensions: inflammation,
  nutrition, weight, symptoms (all four are posted as `dimensions[]`). Validation
  ownership-checks the favorite/supplement, and enforces **add ⇒ inactive supplement /
  remove ⇒ active supplement** (the baseline only folds in active supplements, so the
  opposite would double-count).
- **`PredictiveInsightService::compute(User, ScenarioRequest): PredictiveInsight`** —
  calls `fetchBaseline()` (real DB, read-only: 30-day food averages, active supplement
  daily totals, inflammation daily scores, latest weight, enabled symptom averages).
  The food averages come from
  `DailyIntake::average()` (see Daily intake), which is a **true daily average
  over days that actually have logged meals** (`SUM(nutrient) / COUNT(DISTINCT
  DATE(logged_at))`, raw SQL) — **not** a per-meal `AVG()` and **not** divided by N
  calendar days. (Fixed 2026-06-27: the old per-meal `AVG()` mislabeled a single meal's
  size as a whole day's intake, so the Predict baseline showed an unrealistically low
  daily calorie figure; the same method also backs Trends + supplement-gap recs, all of
  which want a per-day average.) Then projects in memory:
  - **`favorite_daily`:** models the favorite *replacing* an average meal (displacement),
    not piling on a 4th meal — so both nutrition and inflammation subtract one average
    meal before adding the favorite. `avgMealsPerDay` (meals ÷ days-with-meals, from
    `MealEntryRepository`) sets the per-meal share; for inflammation the day's supplement
    contribution is subtracted out first (the favorite swaps a *food* meal, not the stack)
    before dividing the food portion across meals. Falls back to a plain add when there's
    no baseline meal data.
  - **`supplement_add`:** projected supplement stack = current + supplement's
    `dailyNutrients()`; inflammation delta via `scoreMeal(suppNutrients, [])`.
  - **`supplement_remove`:** same but subtract (clamp keys ≥ 0); the supplement IS
    active and baked into baseline, so subtracting is correct.
  - **`free_text`:** no in-memory math — Claude reasons from baseline + description.
  - **Weight** (when selected & a weight reading exists): projected over the *selected*
    window using ~7,700 kcal/kg (`KCAL_PER_KG`) on the daily calorie delta — weight is
    stored in **kg**, and the horizon follows `durationWeeks` (not a fixed 4 weeks).
  - The baseline food average covers **every** nutrient (`DailyIntake::average()`), incl.
    saturated fat, sugar and phosphorus, which the old 23-column query left out.
  - All concrete scenarios project food and the supplement stack separately, then read
    the two together (`intake`: what every displayed row, the weight projection, the
    narrative and `computeGaps(projectedIntake)` use; supplements on meal days only,
    so a baseline with no meal days has no supplements either, ADR 0002). Before
    2026-10-06 the displayed baseline, weight and narrative numbers were food-only. The gap-count row is **omitted when the baseline
    has no nutrition data** (`SupplementRecommendationService::hasNutritionData`) —
    an empty baseline's gap list is computeGaps' no-data `[]`, and "0 → N gaps"
    would misread as the scenario making nutrition worse. Claude writes a 3–5 sentence narrative from the
    computed numbers (`claude-sonnet-4-6`, ≤`NARRATIVE_MAX_TOKENS` = **600** tokens — up
    from 400, which could truncate the required closing "educational estimate, not medical
    advice" sentence at chattier tone settings); falls back to a deterministic
    summary on Claude failure.
- **`PredictiveInsightController`** (`GET /insights/predict` → form, `POST /insights/predict/run`
  → result). Same template serves both (result section hidden when `insight` is null).
  Validation: ownership-checks on `favoriteId`/`supplementId`, enum check on type,
  whitelist on `duration_weeks` and `dimensions`, 300-char cap on `free_text`.
- **DTOs:** `App\Dto\ScenarioRequest` (type, durationWeeks, dimensions, ids, freeText),
  `App\Dto\PredictiveInsight` (narrative, beforeAfter rows, confidenceNote,
  isFreeTextEstimate). `beforeAfter` rows carry `direction` (`better|worse|neutral`)
  so the template can color-code without re-computing.
- **Template** (`templates/insights/predict.html.twig`): inline JS (no Stimulus —
  follows the app's pattern), Bootstrap 5. JS hides/shows sub-fields by type, filters
  favorites by name, character-counts the free-text area, shows a spinner on submit,
  scrolls to results on render.
- **Nav**: More ▸ "Predict 'What If'" — shows the link for Loop+ users, a locked badge
  linking to `/pricing` for others.
- **Claude is mocked in tests** (`FakeAnthropicResponder` returns a canned narrative when
  the system prompt names the "what if" scenario or a "hypothetical dietary change").
  Covered by `tests/E2E/PredictiveInsightTest` (in-process `WebTestCase`: Free user →
  `/pricing` gate, Loop+ form renders, favorite-daily projection renders the before/after
  table + narrative, free-text flags the qualitative estimate, missing/foreign favorite
  rejected).

## Timezones (per-user local time)
Each user has a `User.timezone` (IANA id, e.g. `America/New_York`; column default
`User::DEFAULT_TIMEZONE` = `America/Los_Angeles`, so existing rows behave as before).
Set on **Settings ▸ Timezone** (`TimezoneFormType`/`TimezoneType`,
`SettingsController::timezone`); at registration a hidden field is pre-filled with
the browser zone by **inline JS in `register.html.twig`'s `javascripts` block** (a
suggestion — `RegistrationController` validates it against
`DateTimeZone::listIdentifiers()` and keeps the default otherwise). ⚠️ **Fixed
2026-07-21:** detection was originally wired as a `timezone` Stimulus controller —
but this app never renders `importmap()`, so Stimulus never boots (same reason the
CSRF controller is dormant, see Forms & CSRF) and **every account registered before
the fix silently defaulted to `America/Los_Angeles`** (and, cascading, to imperial
units via `UnitSystem::defaultForTimezone`). The controller file was deleted; any
future browser-side behavior must be inline JS.
- **Mismatch repair banner** (`_timezone_banner.html.twig`, included from
  `base.html.twig` on authenticated pages next to the deletion/verification
  banners): rendered hidden; its inline JS reveals it only when the browser's
  `Intl` zone differs from the account zone **and** their full wall-clock
  (date included) differs at **either of two probe instants — now and ~6 months
  out** (so truly equivalent zones like LA vs Vancouver never nag, but
  seasonal-DST pairs like LA vs Phoenix — identical all summer, wrong after DST
  ends — still do; Copilot catch on PR #163), and the suggestion wasn't
  dismissed (localStorage, keyed by the suggested zone so traveling to a new
  zone re-asks). One click POSTs the browser zone to
  **`POST /settings/timezone/quick`** (`SettingsController::timezoneQuick`, CSRF
  `timezone-quick`, IANA-whitelist-validated, same-origin redirect back with the
  referer's query string preserved so stateful views like `/trends?days=90`
  aren't reset).
  ⚠️ The banner deliberately contains **no `<form>` and no `.alert` class** — it
  rides on *every* authenticated page, so a server-rendered form became the page's
  first `<form>` (broke naive crawler form selection in 8 existing tests) and
  `.alert` matched flash-message assertions; the accept button builds + submits
  the form in JS at click time from `data-endpoint`/`data-csrf`/the detected zone
  (regression-guarded in the test).
  This is also the repair path for the pre-fix user base. Covered by
  `tests/E2E/TimezoneQuickSetTest` (in-process `WebTestCase`: registration stores
  a posted zone + derives units, blank keeps the default, banner markup rides
  hidden, quick-set saves/ignores junk/rejects forged CSRF).

It's an **explicit, forward-looking** setting: changing
it never rewrites past entries (see model below).

- **Storage model — user-local wall-clock.** Per-user data is stored as the user's
  own wall-clock with **no offset** (health imports already drop it; meal `loggedAt`
  comes from EXIF / a `datetime-local` field; symptoms). Doctrine hydrates naive
  `datetime` in the server zone and Twig's `|date` formats in the same zone, so those
  values **render verbatim** and the existing `DATE(...)`/`HOUR(...)` bucketing SQL is
  already the user's local day. We do **not** store UTC and do **not** migrate data.
- **`App\Service\UserTime`** is the one place that answers "what is now/today for this
  user": `now/startOfToday/startOfDaysAgo/startOfTomorrow/localHour`, each taking the
  `User` and an optional reference `$clock` (for the commands' `--now`). It
  `setTimezone()`s the instant into the user's zone so the resulting boundaries'
  `format()` matches the stored wall-clock. **Every** "today"/range boundary goes
  through it — `DashboardController`, `TrendsController`, `HealthMetricsController`,
  `SymptomController`, `MealController` (`parseLoggedAt`), `NotificationService`,
  `GoalEvaluationService`, the windows callers pass to `DailyIntake`, and
  `GoalMetricCalculator`'s baseline trailing window (fixed 2026-07-21, Copilot catch on
  PR #165 — it was server-frame, so users ahead of the server near midnight had
  baselines that missed today's meals; affects goal-creation baseline capture, the
  manual form's hints, and the chat grounding).
- **Day ranges are half-open `[from, to)` (2026-09-30).** Every day-range query in the
  meal, nutrition, health-metric, and symptom repositories compares `< :to`, and callers
  pass `to` = the start of the day after the last one they want (`startOfTomorrow`, the
  next window's start). They compared `<= :to` before, so a row at exactly the next
  midnight counted on **two** days — today's dashboard, the calorie digest's
  "yesterday", and a goal check-in whose window says "today excluded" (the digest's
  trailing average carried a `-1 second` workaround, now removed). The one deliberate
  exception is `HealthMetricRepository::findGlucoseInWindow`: a meal's glucose window is
  two instants, inclusive at both ends. Pinned by `tests/E2E/HalfOpenRangeTest`.
- **Timezone changes go through `App\Service\TimezoneChanger`** (`change(User, $tz)`
  — no-op when unchanged; callers flush). Beyond setting the column it
  **recomputes active goals' materialized `nextRunAt`**: `findDue()` schedules
  purely off that persisted instant and only recomputes after a run fires, so
  without this a user repairing LA→NY still got their next check-in 3 h late in
  the old zone's frame (Copilot catch on PR #163). Wired into Settings ▸
  Timezone (`rescheduleActiveGoals` — the form mutates the User before the
  change is detectable), the mismatch banner's quick-set route, and the
  onboarding notifications step. Covered by a reschedule case in
  `TimezoneQuickSetTest` (asserts the recomputed instant is the user's local
  20:00 in the NEW zone).
- **Scheduling instants are the exception.** Values created server-side and compared
  across users live in the **server frame**: `GoalScheduler::computeNextRun` reads the
  schedule's `hour/minute/dayOfWeek` as the **user's local** time but returns the
  instant in the caller's (server) frame, so `NutritionGoalRepository::findDue()` and
  the hourly cron compare correctly; `SendNotificationsCommand` gates each user on
  their local 8 PM. These instants are converted **back to the user's zone for
  display** via the `user_date` Twig filter (`App\Twig\TimeExtension`) — applied to
  goal `nextRunAt`/`lastRunAt`, check-in `runAt`, API-token + favorite + "Joined"
  dates. Wall-clock fields keep the plain `|date` filter (converting them would
  double-apply the offset).

## Internationalization (i18n — ALL 4 PHASES COMPLETE)
Plan: the private business folder's `NouriLoop-i18n-Plan.md` (4 phases; revised
2026-07-17 after codebase review). **Phase 1 extraction is COMPLETE** (PRs #137–#152,
2026-07-17/18): infrastructure + every user-facing module, with `en` + base `es`
catalogs shipped throughout. **Phase 2 (locale-aware formatting) is COMPLETE**
(2026-07-18, PR #153 — see the Phase 2 block below). **Phase 3 (locale-aware Claude
responses) is COMPLETE** (2026-07-18): the prompt wiring (see the Phase 3 block below)
**and the full catalog set** (see "Translation catalogs" below). **Phase 4 (public
conversion pages, locale-in-URL + hreflang) is COMPLETE** (2026-07-18 — see the
Phase 4 block below). The app ships all
9 locales: `en` (source), `en_GB`, `es` (+ `es_MX`/`es_ES` overrides), `de`, `fr`,
`ru`, `hi`.

### Phase 4: locale-prefixed public conversion pages
- **Scope: the conversion surface only** — `/about`, `/pricing`, `/login`,
  `/register`, `/lp/{slug}` each have a `{_locale}`-prefixed twin route
  (`app_*_i18n`, e.g. `/es-mx/about`) whitelisted by
  **`SupportedLocale::URL_PREFIX_REQUIREMENT`** (dashed lowercase segments:
  `en-gb|es|es-mx|es-es|de|fr|ru|hi`; enum helpers `urlPrefix()`, `hreflang()`,
  `fromUrlPrefix()`). **Bare URLs stay English — no redirects.** Everything else
  keeps its unprefixed route. ⚠️ `security.yaml` has a dedicated PUBLIC_ACCESS rule
  for the prefixed paths (first in access_control) — the bare-path rules don't
  match `/es/login`, and without it anonymous visitors bounce to login.
- **URL locale wins:** `LocaleSubscriber` gives an explicit `_locale` route attr
  top precedence (over the signed-in user's preference AND Accept-Language) —
  a shared `/de/about` link renders German for everyone, which is what Google
  crawls. RouterListener stamps the raw dashed prefix as request locale first;
  the subscriber normalizes it to the enum value (`es-mx` → `es_MX`).
- **`App\Twig\LocalizedRoutesExtension`** (`TWINS` const = the route map):
  `locale_alternates()` → hreflang map (`x-default` + `en` → bare URL, one entry
  per variant; **null for `/lp/*`** — noindex'd pages get no hreflang, ads target
  a variant directly) emitted by base.html.twig's meta block; each variant
  **self-canonicalizes for free** (the canonical is route-derived with params).
  `localized_path(name, params)` keeps cross-links between scoped pages in the
  visitor's language (`/es/lp/fiber`'s CTA → `/es/register`); links to unscoped
  routes (privacy, reset-password) deliberately stay plain `path()`, dropping to
  English. `login.html.twig`/`register.html.twig` cross-links use it too.
  ⚠️ `chrome_suppressed` in base.html.twig matches BOTH `app_landing_page` and
  `app_landing_page_i18n` (caught in review — the twin leaked chrome).
- **Sitemap** lists each about/pricing variant once per locale (priority −0.2 vs
  the bare page); `/lp/*` stays out in every locale. Login/register remain
  unsitemapped as before.
- **Register locale continuity:** registering on a prefixed page stores THAT
  locale on the account (overrides the Accept-Language guess) — the language
  they converted in is the language the app greets them in.
- **Marketing copy extracted** to `about:` (24 keys) / `pricing_page:` (31) /
  `lp:` (53) catalog sections in all 5 full catalogs + override sweeps
  (en_GB spellings, es_ES vocabulary); seo_descriptions + the /about
  WebApplication JSON-LD description localize via translator at render time;
  prices stay `subscription_price()`-driven, never literal. **The
  diabetes-page condition-word rule holds in every language** (glucosa/
  Blutzucker/glycémie/уровень сахара/ब्लड शुगर — never the condition name) and
  `LandingPagesTest` asserts the banned stems (`diabet`, `zuckerkrank`,
  `диабет`, `डायबिटीज`, `मधुमेह`) against every locale variant.
- **Ads impact: none required** — bare `/lp/*` URLs are unchanged (no
  redirects), so live campaigns keep working; locale variants exist for future
  non-English campaigns (target e.g. `/es-mx/lp/diabetes` directly).
- Covered by `tests/E2E/LocalizedPublicPagesTest` (prefixed rendering + junk-404,
  URL-locale-beats-preference, hreflang/canonical, sitemap variants, localized
  cross-links, register continuity) + the multilingual condition-word case in
  `LandingPagesTest`.
**Deliberately English** (all
annotated in place): the **admin pages** (operator-only — John's call 2026-07-18,
parked *not permanent*: revisit when the team grows), the wellness disclaimers, the
welcome letter, team-facing mail, SMS consent copy, importer row-level errors, and
third-party UI terms (HAE setting names) inside translated prose.

### Translation catalogs (Phase 3 second half)
- **Full catalogs** (`messages+intl-icu.<locale>.yaml` + `validators.<locale>.yaml`):
  `de` (Sie-form), `fr` (vous), `ru` (вы — every plural expanded to CLDR
  one/few/many/other), `hi` (आप, common health-app loanwords). **Sparse overrides**:
  `es_MX` (~5 keys — the base es is already LatAm-leaning; Comida/Colación meal
  types), `es_ES` (~40 keys — móvil/ordenador/patatas/beicon…, +34 phone examples),
  `en_GB` (~70 keys — fibre/analyse/favourite/glycaemic/colour; "Favourites" is the
  visible feature label). Overrides rely on the `es_MX → es → en` / `en_GB → en`
  fallback chain — a sparse file is correct, don't pad it.
- **First-pass Claude translations, second-pass independently reviewed** — a human
  native pass is still the eventual bar (the humorous `settings.tone.*` dial labels
  are the weakest spot in every language and are flagged for native curation in each
  file's history). E.164 phone examples are per-locale (+34/+49/+33/+91…).
- **`tests/Translation/CatalogConsistencyTest`** is the mechanical guardrail and runs
  in the unit suite: full catalogs must carry the EXACT en key set (so adding an en
  key without every full catalog fails CI — catalogs ship in the same PR as new
  keys, all languages now, not just es); overrides must be strict subsets of their
  base; **every message must compile as ICU in its own locale**; placeholder-NAME
  sets must match en per key (an ICU-aware scanner — plural/select branch bodies and
  ICU apostrophe-quoting are handled, a naive regex false-positives on both);
  validators files share `validators.es.yaml`'s key set with `{{ limit }}`
  placeholders verbatim (that domain is NOT ICU).
- **Conventions baked into all catalogs** (see any file header): NouriLoop and the
  Free/Loop/Loop+ product names stay; third-party UI terms the user's phone shows
  ("Summarize Data", "Health Auto Export"…) stay English everywhere incl. en_GB;
  prose numbers are plain digits (no grouping — "2300 mg", a comma reads as a
  decimal in es/de); ICU apostrophe rule (an apostrophe before `{`/`}`/`#` escapes —
  fr elisions are rephrased away from braces).
- The old `en` source had three British spellings in `health.import.*`
  (recognised/honoured) — normalized to American in `en`, with the British forms
  moved to `en_GB`. Four custom validator messages missing from `validators.es.yaml`
  since Phase 1 (feedback/meal-photo/valid-image/duplicate-email) were added to all
  five validators catalogs.
- `LocaleSettingsTest` was reshaped for the all-catalogs world: every full language
  must actually REPLACE the English lead on Settings ▸ Language (content-free
  assertion — wording is curated, structure is CatalogConsistencyTest's job), and
  the sparse-override fallback chain is pinned via `en_GB` (uncovered key → plain
  en text) alongside the existing `es_MX → es` case.

### Phase 3: locale-aware Claude responses (wiring)
- **`App\Service\LocalePromptBuilder`** is the locale sibling of `TonePromptBuilder`
  and the ONLY place prompt-locale text lives: `narrative()`/`forUser()` (prose
  surfaces — names the language and restates the guardrails as **surviving
  translation**: the educational-not-medical-advice closing and plain-prose rule apply
  in the target language), `mealAnalysisJson()` (the meal-photo JSON rules),
  `forCurrentLocale()`/`currentLocale()` (request/CLI locale via `LocaleSwitcher`, for
  the anonymous-capable help assistant), and `maxTokens(locale, base)` (**non-English
  narratives get ×1.3 headroom** — es/de/ru run ~20-30% longer, and without it the
  required closing sentence truncates at chatty tone settings). English returns `''`
  everywhere, so `en` prompts stay byte-identical.
- **Wired into all the Claude surfaces:** `FoodAnalysisService` (meal photos — see
  next bullet), `GoalEvaluationService` (check-in narratives, cap 512→×1.3),
  `GoalConversationService` (setup chat — converses in the user's language; the
  fenced `goal-proposal` block's keys/enum values stay English identifiers, only
  `name`/`rationale` localize), `GlucoseInsightsService` (Pattern Coach — replaced its
  Phase-1-era private copy; locale was **already** in the narrative cache key, so a
  language switch busts the cache), `PredictiveInsightService` (both narratives —
  replaced its private copy), and `HelpAssistantService` (answers in the current
  request locale — works for anonymous visitors; the help corpus stays English, only
  the `answer` value localizes; page titles/URLs stay verbatim).
  **`SupplementAnalysisService` deliberately gets NO locale instruction** — its JSON
  has no user-facing prose (product name/serving size are read verbatim off the
  label; nutrient keys are canonical).
- ⚠️ **Ingredient names stay English regardless of user language** —
  `InflammationScoreService`'s food-category adjustments are English keyword matches
  on `ingredients[].name`, so localized names would silently stop them firing. The
  meal-analysis prompt localizes only `description`/`questions`, pins ingredient
  `name` to English, and carries the regional-dish rule ("name it by its regional
  name — do not map to the closest American analog"). Pinned by
  `FoodAnalysisServiceTest` prompt-capture tests.
- **Deterministic fallbacks localize** — most were catalog text since Phase 1; the
  two stragglers Copilot caught on PR #154 are now fixed: the goal check-in's
  Claude-outage fallback + no-data summary compose in the **owner's** locale
  (`goal.checkin.*` ICU keys, explicit `$locale` arg — the cron evaluates *before*
  the delivery dispatcher's `runWithLocale` wrap, so the stored summary must already
  be localized), and the help assistant's fallback answer uses `help_assistant.*`
  keys in the request locale. `FakeAnthropicResponder`'s matchers are unaffected
  (locale text is appended; its `str_contains` anchors still match).
- **Help-assistant retrieval is multilingual** (closed the PR #154 Copilot-flagged
  limitation): `getRelevantPages()` now tokenizes Unicode-safely
  (`/[^\p{L}\p{M}\p{N}]+/u` — `\p{M}` is load-bearing: Devanagari vowel signs/virama
  are combining marks, not letters, and dropping it shatters Hindi words — plus a
  memoized `Transliterator` **Latin-ASCII accent fold** on
  both question and haystack, so "inflamación" ↔ "inflamacion" match and non-Latin
  scripts pass through untouched) and folds **per-locale keyword lines from the
  catalogs** into each topic's haystack — `help_assistant.keywords.<slug>`, shipped in
  en + all five full catalogs (es/de/fr/ru/hi, per `CatalogConsistencyTest`; not
  user-visible text, curated synonym lines translators maintain like any key).
  **All** supported locales' lines are matched, not just the request locale, since
  question language and UI locale are independent (anonymous visitor, English
  browser, Spanish question). STOPWORDS grew folded es/fr/de/ru/hi function words so
  "cómo"/"para" can't spuriously hit a keyword line. Deliberately **not**
  translate-the-query (a Claude call before retrieval would add latency and break
  the deterministic outage fallback) and not embeddings (no embedding vendor;
  overkill at 11 topics). Compatible with the parked help-topic-translation
  decision: translated bodies would simply join the corpus later.
- Covered by `tests/Service/LocalePromptBuilderTest`, prompt-capture cases in
  `FoodAnalysisServiceTest` + `GoalEvaluationServiceTest` (incl. the raised token
  caps), and `GlucoseInsightsServiceTest` (locale reaches the coach prompt + a
  language switch busts the narrative cache).

### Phase 2: locale-aware formatting (dates, numbers, unit-bypass fixes)
- **`App\Service\LocaleFormatter`** is the one PHP-side formatter: `date(value,
  skeleton, ?tz)` and `number(value, fractionDigits)`, keyed to
  **`LocaleSwitcher::getLocale()`** (correct on web requests via `LocaleSubscriber`
  AND inside the delivery pipeline's `runWithLocale()`), memoized per
  locale/skeleton/tz. Dates take **ICU skeletons** (`yMMMd`, `MMMdjm`, `EEEMMMd`,
  `yMMMMEEEEd`, `jm`…) resolved via `IntlDatePatternGenerator`, so `es` renders
  "18 jul 2026" where `en` renders "Jul 18, 2026" from the same call — never pass a
  PHP `format()` string for user-visible dates. ⚠️ ICU 72+ puts a **narrow no-break
  space (U+202F)** before AM/PM — assert `"3:05\u{202F}PM"` in tests, not `'3:05 PM'`.
- **Twig date filters** (`App\Twig\TimeExtension`, both skeleton-taking and
  string-or-DateTime tolerant): **`user_date`** keeps its instant→user-timezone
  conversion and is now locale-aware; new sibling **`local_date`** formats
  **wall-clock values verbatim** (no tz conversion — meal `loggedAt`, symptom dates,
  dashboard "today"). Machine output (form `value=`, `Y-m-d` URL params, the footer
  copyright year) keeps plain `|date`. PHP-side user-facing dates went through
  `LocaleFormatter` too: goal-created flashes (now also converted to the owner's
  timezone — they previously showed the server frame), the account-deletion flash,
  digest notice params in `NotificationService`, and the Trends dinner-time column.
  `GdGoalChartRenderer`'s PNG axis dates + the goal-checkin CLI/report output stay
  English (GD's built-in font is Latin-only — the plan's parked decision).
- **Numbers:** `twig/intl-extra` installed → user-facing numerics use
  **`|format_number({fraction_digit: N})`** (dashboard calories/protein, meal-detail
  macros/micros, favorites, trends nightly table, goals prompt cap). Numeric params
  passed to `|trans` are now **raw numbers** (`|round`, not `|number_format`) — ICU
  MessageFormat localizes bare `{x}` numeric args itself; a pre-formatted string
  bakes in en-US (fixed in the digest email + dashboard protein card).
  `InsightSummaryService` tile headlines + `PredictiveInsightService` before/after
  rows format via `LocaleFormatter->number()` — **predict row
  current/projectedValue are now formatted strings, not floats** (tests updated).
  The es catalog's number literals were de-Anglicized ("2,300 mg" → "2300 mg" — a
  comma is a decimal separator in Spanish).
- **UnitConverter bypass audit — real fixes** (found by the Phase-2 audit; metric
  users previously saw canonical mg/dL / kg): **`App\Twig\UnitExtension`**
  (`glucose_display/glucose_value/glucose_series_value/glucose_unit/
  glucose_area_display`, current user's `UnitSystem` + `LocaleFormatter`; mmol/L
  shows 1 decimal, mg/dL whole) now drives the **meal-detail glucose card** (chips,
  narrative, Chart.js series/axis), the **dashboard glucose tile**, and
  **`/insights/glucose`** (avg rise + avg iAUC — iAUC scales by the same linear
  factor, labeled `{unit}·min`). The affected catalog labels take a `{unit}` param
  instead of hardcoded "mg/dL". **Predict's weight row** converts kg→lb via
  `UnitConverter` per the user's preference (was hardcoded kg). The DTOs
  (`PostprandialResponse`, `AnalyzedMeal`) deliberately stay canonical — conversion
  is view-time only. `GlucoseInsightsService`'s AI-coach *prompt* still says mg/dL
  (prompt context, not display — Phase 3 territory).
- Covered by `tests/Service/LocaleFormatterTest`, `tests/Twig/UnitExtensionTest`,
  an imperial-weight case in `PredictiveInsightServiceTest`, and a
  dates-and-numbers-format-per-locale case in `tests/E2E/LocaleSettingsTest`.

- **`App\Enum\SupportedLocale`** is the single whitelist (string-backed enum, full tags
  like `es_MX`; `en` **must stay the first case** — `Request::getPreferredLanguage()`
  returns the whitelist's first entry on no match). `label()` returns **native-language
  names** (Español, Русский, हिन्दी) that are deliberately never translated.
- **`User.locale`** (migration `Version20260718023013`, enum-typed column like
  `unitSystem`, default `en` so existing rows are unchanged). Set at registration from
  `Accept-Language` (`SupportedLocale::fromRequest`, verified `es-MX` → `es_MX`, no
  match → `en`); changed on **Settings ▸ Language** (`/settings/language`,
  `LanguageFormType`, in the hub's Preferences category). The save flash is translated
  with an **explicit locale arg** (the new one) since it renders after the redirect but
  is translated on the pre-save request.
- **`App\EventSubscriber\LocaleSubscriber`** (kernel.request, **priority 5**): signed-in
  user's preference, else best Accept-Language match. ⚠️ It must run **after the firewall
  (priority 8)** to see the token, but Symfony's `LocaleAwareListener` has already synced
  the translator **at priority 15** by then — so the subscriber sets **both**
  `$request->setLocale()` (drives `app.request.locale` / the `<html lang>` attribute,
  now dynamic in `base.html.twig`) **and** `LocaleSwitcher::setLocale()` (re-propagates
  to the translator). Setting only the request locale silently leaves the page in
  English — this was hit and test-pinned. Display-only: storage stays user-local
  wall-clock (see Timezones); locale never touches queries or stored values.
- **Catalogs:** `translations/messages+intl-icu.<locale>.yaml` — **ICU MessageFormat**
  (use ICU plurals for anything countable; Russian needs 3 forms). Nested YAML flattens
  to dotted keys (`settings.language.title`). `fallbacks: ['en']` in
  `config/packages/translation.yaml`; regional variants additionally fall back
  `es_MX → es → en`, so variant catalogs carry only the strings that differ. Missing
  keys: `php bin/console debug:translation es --only-missing`. **Custom constraint
  messages** use the standard `validators` domain with the English sentence as the key
  (`translations/validators.es.yaml`) — deliberately **not** an intl-icu domain, since
  `{{ limit }}` placeholders would break ICU parsing. Sentences with embedded links use
  ICU placeholders for the markup (`{terms_link}…{link_end}`) rendered with `|raw` —
  catalog text is trusted, and translators can reorder around the link.
  ⚠️ **`|raw` disables escaping for the message's *parameters* too** — any
  user-controlled value interpolated into a `|raw`-rendered message MUST be escaped as
  a parameter first (`s.label|e`), or a crafted value becomes live HTML (XSS caught by
  review on PR #146 — custom symptom labels; regression-tested in
  `SymptomCorrelationPanelTest`). Server data (dates, counts, routes) needs no escape.
- **The delivery module renders in the RECIPIENT's locale** —
  `LocaleSwitcher::runWithLocale($user->getLocale()->value, …)` wraps: the digest
  pipeline in `NotificationService` (in-app notices + email template + SMS body, all
  `delivery.*` keys), `GoalDeliveryDispatcher::dispatch` (so every check-in transport
  inherits it), and the three transactional senders (login-link / reset / verify —
  deliberately the **account's saved locale, not the requester's browser**). The
  welcome letter stays English (parked personal-note decision) and the team-facing
  mails (new-user notice, feedback) stay English on purpose — all three annotated in
  their templates. `gap.suggestion` inside the digest is
  `SupplementRecommendationService` text — localizes with the supplements module.
- **Extracted (newest first):** the **connect-cgm wizard** (all 5 steps + the live
  verification ladder — state alerts pass their live-updating `<span data-fill>` counts
  as raw ICU params, while the arrived-rung line uses server-side ICU + a JS-seeded
  one/other template pair, same pattern as `predict.remaining_tpl`), **glucose insights
  + predict** (glucose page incl. the locked/
  empty/coach states; predict form/results incl. all validation errors;
  `PredictiveInsightService` translator-injected — row labels, confidence notes, the
  deterministic fallback narrative as ICU selects, symptom labels via `SymptomLabeler`,
  macro rows reuse `nutrient_full.*`), the **symptoms page** (check-in incl. slider end-label
  transKeys on `SymptomType`, manage panel, history; labels via `symptom_label()`) and
  **health metrics** (index incl. `MetricSummary::bucketTransKey()`, the full import
  page incl. the three how-to tab bodies as whole-block keys, import flashes — importer
  row-level error strings stay English as machine/log text passed via {error} params),
  the **supplements module** (index incl. gap cards, scan,
  confirm, flashes — the gap labels/suggestions were already service-localized in the
  delivery PR), the **notification inbox** (page chrome + the gone-target
  flash; ⚠️ stored `Notification.title/body` rows are data written at send time —
  localizing them is the delivery/emails module's per-recipient-locale work, NOT a
  template concern) and **favorites** (picker incl. sort options, per-card log/edit
  forms, all four flashes) — the **trends/insights module** — the full 1,262-line
  `trends/index.html.twig` (tiles, all detail panels + About explainers, correlation
  prose, nausea triggers, the dinner→glucose→sleep table) with a Twig-seeded `L` dict
  for the `{% verbatim %}` Chart.js strings and `document.documentElement.lang` for JS
  date formatting; `InsightSummaryService` is translator-injected (`insight.*` keys);
  `HealthMetricType` gained `transKey()` (`metric.*`); symptom labels in view data flow
  through the new **`App\Service\SymptomLabeler`** (extracted from the Twig extension so
  controllers/services share the built-in-translation rule) — the **goals module** — index/chat/manual-form/detail; six goal
  enums gained `transKey()`; the goal summary renders via a `goal.summary_pattern` ICU
  key composed from translated enum labels (**`GoalDefinition::summary()` itself stays
  English — it can feed prompts**); `GoalSafetyValidator` + the chat greeting are
  translator-injected; **`DietaryReference` stays English** (it grounds the goal-chat
  system prompt) — the manual form's display copy of each note is translated at the
  controller (`goal.ref.*`) — the **settings module** — hub (`CATEGORIES` labels are now
  translation keys, rendered `|trans`; `SettingsHubTest` translates them too), all
  sub-pages incl. tone (the humorous dial labels/descriptors got a first-pass Spanish
  adaptation — flag for native curation), API tokens (incl. the usage docs), and
  account deletion; `UnitSystem`/`NotificationChannel` gained `transKey()`; every
  `SettingsController` flash + the two SMS form errors. ⚠️ **The SMS consent label and
  STOP/HELP disclosure deliberately stay English** (parked TCPA compliance decision —
  annotated in `NotificationPreferencesFormType` and the template); `SubscriptionTier`
  labels (Free/Loop/Loop+) stay as product names — Settings ▸ Language, the **meal module** — log / Confirm & Refine
  / meal detail incl. the glucose-response card (ICU narrative with a composed
  `{return_clause}`, reading-count plurals, an ICU-select confidence badge, Chart.js
  axis/series labels passed via `|json_encode`), `MealEntryFormType`, and all
  `MealController` flashes (constructor-injected translator — too many actions for
  per-method args); shared **`macro.*`** and **`nutrient.*`** catalog sections were
  introduced here for reuse by supplements/trends — the **dashboard** (ICU-select greeting
  `{period, select, …}`, day-ago plurals, inflammation band labels/takeaways keyed by
  band slug, protein/glucose/symptom-check-in cards; `MealType` gained **`transKey()`**
  — templates render `type.transKey|trans` while `label()` stays for PHP/legacy contexts
  until those modules are extracted), the **onboarding overlay** (all four step
  partials + the two `OnboardingController` flashes; the dismiss `confirm()` uses
  `|trans|e('js')` inside the inline handler), the **shared chrome** — `base.html.twig`
  (navbar + More dropdown, notification bell incl. an ICU-plural "N new" badge, floating
  help button, footer feedback link), the deletion/verification banners, and the cookie
  consent banner (the wellness disclaimers stay English — parked per the plan; date
  *formats* like the deletion banner's `F j, Y` are Phase 2) — and the **auth module** — login page (incl.
  hero + SVG loop labels), registration, password reset (all 3 pages), magic-link
  request/sent/confirm, `_password_toggle` aria-labels (via a Twig-seeded JS `LABELS`
  dict), the auth form types' labels, and the auth controllers' flashes
  (`TranslatorInterface` injected). Login auth errors + the reset bundle's token errors
  were already localized via their bundles' own domains (`security`,
  `ResetPasswordBundle` — Symfony ships all our languages). Everything else is still
  hardcoded English. The auth **email templates** (login-link, reset, verify) ARE
  extracted (`delivery.email.*` / `delivery.transactional.*` subjects) and send in
  the account's saved locale via their controllers' `runWithLocale` wraps; only the
  **welcome letter** stays English (parked — John's personal note).
- **Symptom names render via `symptom_label()`** (`App\Twig\SymptomLabelExtension`):
  built-ins translate through their stable `typeKey` (`symptom.builtin.*`, all 8 incl.
  the GLP-1 pair; the seeded English `label` column is only a missing-key fallback),
  **custom symptoms are user data and render verbatim — never translated**. Wired into
  the dashboard check-in card; the /symptoms and Trends modules must use the same
  helper when extracted (pinned by a `LocaleSettingsTest` case).
- **Tests stay in `en`** (seeded users default to `en`, so existing English-string
  assertions are unaffected). Covered by `tests/E2E/LocaleSettingsTest` (in-process
  `WebTestCase`: page render + native labels, es switch translates page **and** flash +
  persists, fr-without-catalog falls back to English with no raw keys, `es_MX` →
  base-`es` catalog fallback, registration Accept-Language detection incl.
  unsupported-language → `en`).

## Registration (open sign-up + optional coupon)
Registration is **open to the public** (soft-launch, 2026-06-28): anyone can sign up at
`/register` and lands on the **Free tier**.

- **Auto-login after registration (2026-07-21, new-user Wave 3):** a successful
  registration signs the new user in programmatically (`Security::login` on the `main`
  firewall with an enabled `RememberMeBadge`, so they get the always-on 180-day cookie
  like any form login) and redirects **straight to the dashboard**, where the onboarding
  overlay is waiting — no more bounce through the login page. The success flash is
  `auth.register.welcome`; the **honeypot fake-success still redirects to `/login`**
  with the old `auth.register.success` flash (there's no user to sign in). The one-shot
  `track_event: sign_up` flash therefore now fires on the **authenticated dashboard**
  render (which is also what makes the Meta Pixel load once there — the same pending-
  flash mechanism as `purchase` on `/subscription/success`; see Analytics/Meta Pixel).
- **Registration form UX (same Wave 3 change):** the form the public sees is 4 fields —
  name, email, password, confirm. The **coupon field hides behind a "Have a coupon?"
  disclosure link below the password block** (inline JS reveal; server-rendered open when
  the field came back non-empty or with an error, so the invalid-coupon re-render shows
  the message — Panther tests must click `#coupon-toggle` before filling the field, a
  real browser can't type into `d-none`). **Live password feedback** (inline JS, advisory
  only — server validation unchanged): a min-length hint under the password
  (`#pw-length-hint`) and a match/mismatch hint under the confirm field
  (`#pw-match-hint`), each appearing once the user types (`auth.register.pw_*` keys). The registration form has an **optional
"Coupon" field** (relabeled from "Beta code" 2026-07-09): blank → Free tier; a **valid**
coupon is redeemed and a **beta-flagged** coupon still grants a complimentary **time-limited
Loop+ comp** (see Subscriptions ▸ comp). A **non-blank but invalid** coupon is rejected (generic
error, no enumeration) — only a *blank* coupon skips the check. Under the hood a coupon **is**
an `InvitationCode`; admins mint/manage them exactly as before (each single-use, good for
**48 hours**, `InvitationCodeManager::TTL_HOURS`). **No public request-a-code page** — the
old `/request-invite` solicitation flow (`InvitationRequest` entity/controller/form,
`email/invite_request.html.twig`, the `invitation_request` table) was **removed 2026-07-09**
(table dropped in `Version20260709202245`); beta testers are now recruited through other
channels, and a coupon handed out that way routes to the same beta-tester grant.
- **Email deliverability warning (warn-only, 2026-07-24):** after the form validates
  (and after honeypot/Turnstile), `App\Service\EmailDeliverabilityChecker::assess()`
  flags the address when its domain is (a) on a conservative known-misspelling map
  (`gmial.com → gmail.com` …, checked FIRST since typo-squats often have real MX) or
  (b) has no MX/A/AAAA record (`checkdnsrr`, trailing-dot absolute, **fails open** on
  resolver errors). A flagged address re-renders the form with a warning — the typo
  case offers a one-click "Use {suggestion}" button (inline JS fills the field) —
  plus a hidden `email_warning_ack` carrying the warned address: an **unchanged
  resubmit is accepted** (warn once, never block — John's call: the user is the
  authority on their own address), while an edited address re-arms the check.
  Born from a real registration with an undeliverable address (2026-07-24); catches
  misspelled domains, not wrong-mailbox typos (that's the profile email-change flow).
  ⚠️ **Tests/CI make no DNS calls** (same rule as the disabled HIBP check):
  `config/services_test.yaml` swaps in `App\Tests\Stub\StubEmailDeliverabilityChecker`
  (inherits the typo map; only its `NO_MX_DOMAIN` const fails the MX question), which
  is why `EmailDeliverabilityChecker` is non-final with a protected `domainAccepts()`.
  Keys `auth.register.email_warn_{typo,use,no_mx,confirm}` in en + all five full
  catalogs. Covered by `tests/Service/EmailDeliverabilityCheckerTest` and
  `tests/E2E/RegistrationEmailWarningTest` (warn→ack→accept, per-address re-arm,
  healthy address passes straight through).
- **Spam defenses on the now-public `/register`** (the invite gate used to be the de-facto
  bot wall): a **honeypot** `website` field (filled → fake-success redirect to login, no
  account) **and Cloudflare Turnstile** (`TurnstileValidator`, no-op without keys, same as
  the feedback form). **Email ownership verification shipped 2026-07-05**
  (the launch-time fast-follow) as a **soft gate** — new registrations start unverified
  and the app stays fully usable; see **Email ownership verification** under Auth.

- **Model:** `InvitationCode` (`code` 32-char hex stored **plaintext** — it's shown
  back to the admin, unlike the hashed `ApiToken`; unique `uq_invitation_code`),
  `createdAt`/`expiresAt`, `createdBy` (admin, audit), and `usedBy`/`usedAt`.
  `usedBy` is a `OneToOne` to `User` (inverse `User::getInvitationCode()`); both FKs
  are `ON DELETE SET NULL`, so the admin user-delete flow needs no extra handling.
  Migration `Version20260612192801`.
- **Mint/manage:** on **`/admin/users`** (`AdminUserController::createInvitation` /
  `deleteInvitation`, CSRF `admin-invite-create` / `admin-invite-delete-<id>`). The
  page shows outstanding (unused) codes with Active/Expired status and a **Revoke**
  button (used codes can't be revoked — they're historical). `InvitationCodeManager`
  generates + persists; `InvitationCodeRepository::findOutstanding()` lists them.
- **Redeem:** `RegistrationFormType` has an unmapped, **optional** `invitationCode` field
  (labeled **"Coupon (optional)"** in the UI; the internal field name is unchanged);
  when it's **non-blank**, `RegistrationController` looks it up via
  `InvitationCodeRepository::findValid()` (matches code AND unused AND unexpired) — on miss it
  adds a **generic** field error ("Invalid or expired coupon.", no enumeration) and
  re-renders; on hit it persists the user and `redeem()`s the code in one flush. A **blank**
  code skips all of this and the user is created on Free. The unique `usedBy` join column is
  the backstop against a single-use race.
- **Beta-tester coupons** carry an `InvitationCode.beta_tester` flag (checkbox on the
  mint form; `InvitationCodeManager::create($admin, $betaTester)`). When such a code
  is redeemed, `RegistrationController` calls `SubscriptionService::grantBetaTesterComp`
  so the new user gets a complimentary **Loop+ for `SubscriptionService::BETA_COMP_DURATION`**
  — **45 days since 2026-07-26**, shortened from a year (see Subscriptions / billing ▸ comp). A plain code grants nothing. Migration `Version20260622175104`.
- Each user's **`/admin/users`** row shows the code they registered with
  (`u.invitationCode.code`), if any.
- Covered by `tests/E2E/PublicRegistrationTest` (no-code → Free user lands authenticated
  on `/`, honeypot → no account + old login redirect, invalid code → 422 reject) +
  `RegistrationTest` (Panther: coupon disclosure opened before typing, invalid rejected,
  valid succeeds straight onto the dashboard, live password hints react while typing)
  + `BetaGrandfatheringTest` (beta code → Loop+ comp) + `WelcomeEmailTest`.

### Registration attribution (first-touch UTM capture + counts endpoint, 2026-07-25)
The NouriLoop half of **§4.2 of `Campaigner/CAMPAIGN-PLAN-EVALUATION-PLAN.md`**: the
first-party ground truth for "how many registrations did each ad campaign actually
produce?". GA4 can't answer it — it's consent-gated, and Marketing Plan §10 already puts
the undercount at 30–50% for this 45–70 audience, so its figure is a **floor, not a
count**. Building a conversion-integrity rule on that number reproduces the July
mis-attribution in a new place. Our own DB is exact.

- **Capture — `App\Service\AttributionCapture` + `App\EventSubscriber\AttributionSubscriber`**
  (kernel.request, priority 5, GET + main requests only). The subscriber stashes
  `utm_source`/`utm_medium`/`utm_campaign`/`utm_content`/`gclid` (the `PARAMS` const —
  exactly what the ad URLs carry; deliberately **not** `utm_term`, the referrer, or
  anything else that could drift toward a per-visitor profile) in the session under
  `AttributionCapture::SESSION_KEY`. **First touch wins** — an existing capture is never
  overwritten, so the campaign that *found* the user keeps the credit. It rides on every
  page, not just `/lp/*`, since an ad URL can point anywhere. ⚠️ It **no-ops unless the
  URL actually carries a parameter**, so an ordinary visitor never gets a session started.
  ⚠️ It also **skips the `^/api` prefix explicitly** (`STATELESS_PREFIX`, mirrors the
  firewall pattern — keep the two in step): being GET-only is *not* enough, since this
  very feature added `GET /api/metrics/registrations`, and a bearer-token client that
  happened to send a tracked param would otherwise be handed a session cookie on a
  stateless request (Copilot catch on PR #183; Symfony doesn't set the `_stateless`
  request attribute from the firewall config in this version, so a path match it is —
  regression-pinned in the test). Values
  are query-string data, i.e. attacker-controllable: control characters are stripped and
  the length capped at 255 before anything is stored (the counts endpoint echoes campaign
  names back verbatim).
- **Persist:** `RegistrationController` calls `applyTo($user, $request)` just before the
  persist — session capture first, the registration request's own params as a fallback.
  Nothing captured → all five columns stay null (direct/organic, or a return visit after
  the session expired). Columns are five nullable `VARCHAR(255)` on the **`user` row**
  (migration `Version20260725120000`, plus `idx_user_created_campaign` on
  `(created_at, utm_campaign)` covering the counts query) — so admin user-delete and
  `AccountDeletionService` need no change, and "start fresh" (`purgeUserData`) leaves them
  alone on purpose: the registration still happened.
- **Read — `GET /api/metrics/registrations?from=&to=`** (`App\Controller\Api\MetricsApiController`,
  bearer token on the existing stateless `^/api` firewall). Returns
  `{from, to, total, campaigns: [{utm_campaign, count}, …]}`, biggest first with a stable
  name tie-break. Bounds are **inclusive whole days in the server frame** (where
  `created_at` is written; `to` is expanded to end-of-day internally), default the last 30
  days ending today; junk or reversed dates are a **400**, not a silently different window.
  Unattributed registrations come back as a **`utm_campaign: null` bucket** — deliberately
  returned rather than dropped, so a consumer can't mistake the attributed slice for the
  whole. Query lives in `UserRepository::countRegistrationsByCampaign`.
- ⚠️ **Admin-only, counts-only — both are load-bearing.** These are whole-account figures,
  so `security.yaml` has a dedicated **`^/api/metrics` → ROLE_ADMIN`** rule **before** the
  `^/api` ROLE_USER rule (first match wins), with a `denyAccessUnlessGranted` in the
  controller as belt-and-braces — an ordinary user's API token must not read them. And the
  endpoint returns **counts only, never user identifiers, emails, or anything about an
  individual**: that is the same posture that keeps the Meta Pixel off every authenticated
  page, and it is not an implementation detail to relax later. **Do not let this endpoint
  grow a per-user shape.**
- **Known limitation, stated on purpose:** first-touch capture misses someone who clicks an
  ad, leaves, and returns directly days later — they land in the null bucket. GA4 models
  that with data-driven attribution; a column doesn't. At a handful of registrations a
  month it's noise; don't pre-build multi-touch for it.
- **ToS §9 "Analytics and cookies"** gained a paragraph disclosing the capture in plain
  language (first-party only, counts by campaign, never a profile, never shared) — note
  this does mean an ad-fed visitor gets a first-party session cookie before touching the
  consent banner, which the paragraph is written to cover.
- Covered by `tests/E2E/AttributionTest` (in-process `WebTestCase`: landing params survive
  intervening pages onto the user row, first-touch beats a later campaign, direct signup
  stores nothing, oversized/control-char/blank params are cleaned, endpoint grouping incl.
  the null bucket + both window boundaries, junk dates 400, non-admin token 403, no token 401,
  and an API request setting no session cookie).

### Feedback form (public "Send us feedback")
A footer link on **every** page (`base.html.twig`, next to the copyright) → **`/feedback`**
(`FeedbackController`, `^/feedback` is `PUBLIC_ACCESS`, so it works logged-in or out).
`FeedbackFormType`: **name + email optional, feedback required** (5000-char cap), prefilled
from the signed-in user when present. **Not persisted** — emailed to the team address
**`ADMIN_EMAIL`** via a `TemplatedEmail`
(`email/feedback.html.twig`) using the global `MAILER_FROM`, with **Reply-To set to the
submitter** when they leave an email so a reply lands back with them. **Spam defenses (two
layers, since it's an unauthenticated POST that triggers mail):** a **honeypot** unmapped
`website` field — any value → silent success, persist nothing — **and Cloudflare Turnstile**
(`App\Service\TurnstileValidator`). Turnstile mirrors the app's optional-integration pattern
(cf. Twilio): keyed by `TURNSTILE_SITEKEY` / `TURNSTILE_SECRET` in `.env.local`, and **a
no-op that passes when no secret is set**, so dev/test/CI work without keys. `verify()` posts
the token (+ client IP) to `challenges.cloudflare.com/.../siteverify` and fails closed on a
missing token or any error; the widget renders only when `isEnabled()`. (The same honeypot +
Turnstile defenses also guard `/register`.) Because it isn't stored, a
mail-transport failure **shows an error and re-renders** (not a false "sent" page) — unlike
the best-effort admin notices, which have the DB row as a backstop. Covered by
`tests/E2E/FeedbackTest` (public render, email sent + Reply-To, anonymous/no-email send,
honeypot sends nothing, blank rejected 422).

## Admin / user management
`ROLE_ADMIN`-gated, hidden from regular users (nav link guarded by
`is_granted('ROLE_ADMIN')`).

- **`/admin/users`** (`AdminUserController`): list users, **enable/disable**
  (toggles `is_active`), and **delete**. You can't disable/delete your own
  account; actions are CSRF-protected. Delete calls
  **`AccountDeletionService::purgeUser()`** (see **Account deletion** below) — the
  full wipe logic (files + FK-safe row deletes) lives there now, shared with the
  self-service grace-period purge cron so admin and self-service delete can't
  drift. A row with a pending self-service deletion shows a **"Pending deletion ·
  `<date>`"** badge, with a **Cancel deletion** action alongside Enable/Disable.
- **List UI — search, pagination, and shared badge/action controls.** The page
  grew one badge or button at a time until it was a mix of solid/subtle badges
  and an Actions column ranging from 1–4 buttons of different widths (icon-only
  Delete next to full-text "Disable AI Coach"). Fixed with two local Twig macros
  in `templates/admin/users/index.html.twig` (`{% macro %}` + `{% import _self as
  ui %}` — the same idiom as `templates/insights/glucose.html.twig`, not a new
  one): **`ui.badge(label, variant, subtle, title)`** is the one badge renderer
  for the whole page (main table, invitation-codes table, invitation-requests
  count) — `subtle=true` (default) for status info (Enabled/Disabled,
  Pending deletion, Active/Inactive, code Active/Expired, Beta), `subtle=false`
  for identity/role info (Admin, You), kept visually distinct on purpose but
  routed through the same macro so the markup can't drift again. **`ui.rowActions(u,
  pendingDeletionDate, tierAction)`** collapses every per-row action (tier
  Upgrade/Downgrade, Enable/Disable, Cancel deletion, Delete) into one
  uniformly-sized "⋮" `dropdown-menu` — a `<form>` per `dropdown-item` is a
  standard, supported Bootstrap pattern. Every row looks identical regardless
  of how many actions apply; a future admin action is just a new menu item.
  Bootstrap's dropdown auto-flips upward near the bottom of the table rather
  than clipping against `.table-responsive`'s overflow — verified live, not
  just assumed.
- **Tier Upgrade/Downgrade (2026-07-24, replaced the per-user AI Coach toggle):**
  one-step ladder actions in the row's ⋮ menu (`app_admin_user_tier_up`/`_down`,
  CSRF `admin-tier-up/-down-<id>`). They adjust the **comp only** — Upgrade grants
  a **permanent** comp at the next tier (the beta-grandfathering mechanism;
  `SubscriptionTier::fromRank()` steps the ladder), Downgrade lowers it one step
  and **clears the comp entirely once it stops doing anything** (target ≤ paid
  tier). **Stripe billing is never touched**: `SubscriptionService::paidTier()`
  (active/in-grace sub, ignoring comps) is the floor — below it the menu shows a
  disabled "Downgrade (paid via Stripe)" explainer and the controller refuses a
  stale form. Self-upgrade is allowed (testing, the old toggle's posture).
  Downgrading Loop+ → Free = two clicks, by design. Covered by
  `tests/E2E/AdminTierControlsTest` (ladder up/down, comp-cleared-at-Free,
  paid-floor bound incl. stale-form refusal, non-admin 403).
- **List UI — server-side search + pagination + filters**, the first paginated list
  in the app (no existing convention, so this establishes one — a numbered-page-strip
  library wasn't worth adding for one internal admin table).
  `UserRepository::searchPage(AdminUserFilters, $page, $perPage)` / `countSearch(AdminUserFilters)`
  do a plain `LIKE` on email/display name (the search text is literal — `%`, `_` and `\`
  are escaped since 2026-09-30, so `a_b` doesn't also match `axb`) + the filter predicates below +
  `setFirstResult`/`setMaxResults` — no fetch joins, so no
  `Doctrine\ORM\Tools\Pagination\Paginator` needed. `AdminUserController::index()` reads
  `?q=`, `?page=` (25/page, fixed), `?tier=`, `?activity=`, `?status=`, clamps `page` to
  the real last page, and only computes `activeUserIds`/`pendingDeletionDates`/`tierLabels`
  for the current page's users. Template shows a plain GET filter bar (bookmarkable, no
  JS) and a Prev/Next + "Showing X–Y of Z" footer; **the pagination links carry all
  active filters** (`filterParams|merge({page})`) so a filter never drops at a page
  boundary, while **Clear** links to the bare route to reset every filter. Invitation
  codes (`findOutstanding()`) are self-limiting and don't need this.
- **Filters (`App\Dto\AdminUserFilters` — shared verbatim by `searchPage`/`countSearch`
  so page + total can't disagree; carries `now`/`activeCutoff` so the same instant drives
  the SQL and the badges):**
  - **Plan** (`?tier=free|loop|loop_plus`) filters on the **effective** tier, expressed
    in DQL by `UserRepository::applyTierFilter()` mirroring
    `SubscriptionService::effectiveTier` (highest of an active comp and an active/in-grace
    sub). **Not** the raw `subscription_tier` column: a grandfathered beta tester is stored
    Free but comped Loop+, so a raw-column filter would file them under the wrong heading.
    The predicates use `IS NOT NULL` guards so a NULL comp/status yields strict false, not
    SQL-NULL (else the Free case's `NOT(...)` drops the row). `SubscriptionService::ACTIVE_STATUSES`
    /`GRACE_STATUSES` are `public` so the query reuses them.
  - **Activity** (`?activity=active|inactive`) mirrors the badge's "logged in AND logged a
    meal in the last 7 days"; Inactive is the exact SQL negation (login-null/old OR no
    recent meal via a `MealEntry` subquery).
  - **Status** (`?status=enabled|disabled|pending_deletion|unverified`) — plain column
    predicates (`isActive`, `deletionRequestedAt IS NOT NULL`, `emailVerifiedAt IS NULL`).
  - Junk values are whitelisted away in the controller (ignored, like the `page` clamp).
- **Plan badge:** each row shows the effective tier in the **Status** cell via `ui.badge()`
  (`success` Loop+, `info` Loop, subtle `secondary` Free), with a tooltip
  (`AdminUserController::tierTooltip`) spelling out *why* — e.g. `Loop+ · complimentary,
  expires …` or `Loop · past_due, access until …` — so a comp or lapsing grace isn't
  invisible.
- **Reports:** `/admin/reports/favorites` (`AdminReportController`) — favorite-reuse
  savings across all users: analyses skipped + tokens saved, with a per-user
  breakdown (`FavoriteRepository::savingsByUser`).
- **Grant admin:** `php bin/console app:make-admin <email>` (`--revoke` to remove).
  Roles aren't carried by DB data imports, so a freshly-seeded prod user needs this.
- **Grant a comp from the CLI:** `php bin/console app:grant-comp <email>`
  (`GrantCompCommand`) — the CLI sibling of the admin Upgrade/Downgrade actions
  (those step one tier at a time; this sets an exact tier + expiry). Defaults
  to a **permanent Loop+** comp; `--tier=loop|loop_plus`, `--expires=` (absolute
  `2027-07-19` or relative `+1 year`; omitted = permanent, past dates warn but grant),
  `--revoke` (clears the comp via `SubscriptionService::revokeComp`), `--dry-run` (reports
  "Would grant/clear…" and returns **before** touching the user — it used to mutate the
  managed entity and just skip the flush, printing as if done).
  (The old `--ai-coach`/`--no-ai-coach` flags were retired 2026-07-24 with the
  per-user entitlement — the coach follows the Loop+ tier now.) Grants go through
  the same `SubscriptionService::grantComp` path as coupon redemption/grandfathering;
  an existing comp is replaced with a note (the before/after effective tier is printed).
  Covered by `tests/E2E/GrantCompCommandTest` (in-process `WebTestCase` + CommandTester:
  default grant, tier/expiry options, revoke, dry-run writes nothing, unknown
  email / `--tier=free` rejected).
- **New-registration notice:** `RegistrationController` emails the team address
  **`ADMIN_EMAIL`** (a single configurable recipient, **decoupled from any admin account's
  login email** — was the old `findActiveAdminEmails` lookup, removed) — best-effort (wrapped
  in try/catch + logged), so a mail failure never blocks registration. Template
  `templates/email/new_user.html.twig`.
- **`ADMIN_EMAIL` (env, non-secret; `.env` default `info@nouriloop.com` — an alias of
  the john@ IONOS mailbox, switched 2026-07-13; override in
  `.env.local`)** is the single recipient for all **team-facing** mail — the new-user notice
  and the `/feedback` form — bound as `string $adminEmail` in
  `config/services.yaml`. Change this one var to redirect where those land.

## Navbar (shared chrome, `base.html.twig`)
The signed-in navbar is **`navbar-expand-lg`** — the hamburger holds until **992px**.
⚠️ It was `navbar-expand-md` until 2026-08-01, which expanded the full horizontal bar at
768px: iPad-portrait widths (768/810/834) can't fit five main items + bell + Upgrade +
the user dropdown, so "Insights & Trends" and "Upgrade" wrapped *inside* their own
`nav-link` — icon on one line, label under it — and those items' baselines dropped below
their neighbours'. It read as a broken bar, not a tight one. Two parts to the fix, both
needed:
- the **lg breakpoint**, so every tablet-portrait width gets the stacked drawer (which
  has always looked right) rather than a bar that doesn't fit;
- a **`white-space: nowrap`** rule on `.navbar-expand-lg .navbar-nav .nav-link`, scoped
  inside `@media (min-width: 992px)` so the drawer (where wrapping is fine and long
  labels need it) is untouched. This is the structural guard: an item's icon can never
  again sit above its own label, whatever the label length. If the bar genuinely runs
  out of room the whole right-hand `<ul>` wraps as a unit, which stays readable.
- ⚠️ **The user-menu label is USER DATA and had to be bounded** (Copilot catch on
  PR #189): `displayName` accepts up to 100 chars, so the nowrap rule above turned a
  long name into **page-wide horizontal overflow** instead of a wrap — measured at
  1678px of document width in a 992px viewport. It now renders inside a
  **`.navbar-username`** span (ellipsised, `title` carries the full name). The cap is
  **tiered on purpose**: 14rem where there's room (the full-width drawer below lg, and
  xl up), but **7rem in the 992–1199 band**, because a 14rem name still overflowed by
  ~52px at 992. Deliberately not scoped to the expanded state — an unbroken 100-char
  name overflows the drawer too. An ordinary name is well under 7rem and never
  truncates.
- **Headroom is checked in the longest language, not English.** At exactly 992px the
  German labels (`Analysen & Trends`, `Erfassen`) measure ~952px of 992 — ~40px slack,
  one row. Adding a top-level nav item, or lengthening a `chrome.nav.*` label in any
  catalog, spends that margin: re-measure at 992 in `de` before shipping one, and move
  the item under **More** rather than letting the bar wrap.
- Verified at 375, 768 (drawer + stacked menu), 992 (tightest expanded), 1024
  (iPad landscape) and 1200 — each with a 100-char display name, and 992 additionally
  with the German labels swapped in: no horizontal overflow, one row, at every width.

## Settings hub (`/settings`)
The nine+ settings pages (Timezone, Units, Language, GLP-1 mode, Voice & tone,
Notifications, API tokens, Subscription, Profile, Account) used to be a flat list in the navbar dropdown.
**`SettingsController::CATEGORIES`** (public const, single source of truth, mirrors
`HelpController::TOPICS`) groups them into **Preferences / Notifications / Security
& API / Billing / Account**; `GET /settings` (`app_settings_index`) renders
`settings/index.html.twig` as a card grid per category (same card-grid pattern as the
`/help` index). ⚠️ **Type scale is deliberate** (fixed 2026-07-24 — John found the page
hard to scan): the category headings and the item cards were BOTH h5-sized/bold, which
flattened the hierarchy into one wall of equal text. Headings are now `h2.h5` + a
`border-bottom` rule and items are `h3.h6` (1rem = body size, semibold) — items must
stay **smaller than** their heading, and deliberately no smaller than 1rem (the audience
skews older). The `/help` index keeps the bigger card titles on purpose: it has one
heading and its cards carry a summary line, so nothing flattens there. The navbar's user
dropdown now links to this one hub page instead of
listing every sub-page; each sub-page template gained a small "← Settings" back-link
since it's no longer reached directly from the navbar. None of the underlying
routes/pages changed — this is purely a landing page + nav simplification.

### Profile (name & sign-in email change, 2026-07-24)
**Settings ▸ Profile** (`/settings/profile`, `SettingsController::profile`,
`ProfileFormType` — all fields unmapped on purpose; Account category, listed before
the deletion page). Display name edits save plainly. **Email changes are
CONFIRM-FIRST**: nothing on the account changes until the NEW address opens a
**`UriSigner`-signed, 24 h** link (`GET /settings/profile/confirm-email` with
`uid`+`new`+`old` params — `old` binds the link to the address it was issued
against, so the moment any change lands every earlier outstanding link dies;
no replay window for a superseded request, Copilot catch on PR #180) — so a
typo'd new address is harmless (the old one keeps working) and the confirmed
address is stamped verified (`emailVerifiedAt = now`, clicking IS inbox proof).
A confirmation-send transport failure is caught → logged → retryable
`settings.profile.send_failed` flash, never a 500. Born from a real signup with an undeliverable address —
this is the self-repair path (the user stays signed in via the 180-day cookie even
when their stored email is broken).
- **Guards, in order:** current-password check first (a hijacked session must not
  redirect the account's recovery channel; name-only edits never ask — the field
  is JS-revealed only when the email differs, server-enforced regardless),
  uniqueness (re-checked again at consume time), the registration flow's
  **deliverability warn-once** (shared `EmailDeliverabilityChecker` + the
  `auth.register.email_warn_*` keys), and the **`email_verification` rate limiter**
  (key `email-change-user-<id>` — the request mails an arbitrary address, so it
  must not be a mail cannon). The consume route additionally requires the signed-in
  user to MATCH the link's `uid`, and is deliberately a GET (scanners prefetch
  unauthenticated and bounce off the login wall — unlike the magic-link consume,
  only the signed-in owner can act here). ⚠️ `UriSigner::sign()` reads an **int
  expiration as an absolute timestamp** — pass a `DateInterval` (a bare `86400`
  means "expired in 1970"; hit in tests).
- **On confirm:** old address gets a best-effort "if this wasn't you" notice
  (`email/email_changed_notice.html.twig`), and the Stripe customer email is
  best-effort synced (`SubscriptionService::syncCustomerEmail`, never throws) so
  receipts don't chase a dead inbox. Both confirmation
  (`email/confirm_email_change.html.twig`) and notice send in the account's saved
  locale; subjects `delivery.transactional.email_change{,d}_subject`.
- **i18n:** `settings.item.profile` + `settings.profile.*` +
  `delivery.email.change_*`/`changed_notice` in en + all five full catalogs.
- Covered by `tests/E2E/ProfileSettingsTest` (name-only no-password save, password
  gate + uniqueness, full confirm round trip incl. verification stamp + old-inbox
  notice, tampered-signature + wrong-account refusals, deliverability warn→ack,
  hub listing).

## Account deletion (grace period)
Self-service "delete my data" and "delete my account," reachable from **Settings ▸
Account** (`/settings/account`) — closes a gap the ToS already promised ("you may
delete your account at any time") but the app had no UI for; previously only an admin
could delete a user.

- **`App\Service\AccountDeletionService`** is the single place every deletion path
  goes through (self-service, admin-triggered, and the purge cron all call into it,
  so the wipe logic can't drift):
  - **`requestDeletion(User, now)`** — starts a **`GRACE_DAYS` = 30**-day countdown by
    stamping `User.deletion_requested_at` (migration `Version20260701120000`). This
    deliberately does **not** touch `isActive`/`UserChecker` — a disabled account can't
    log in at all (checked pre-auth), which would break "log back in to cancel," so
    the account stays **fully functional** through the grace period; a persistent
    top-of-page banner (`templates/_deletion_banner.html.twig`, included in
    `base.html.twig` via the **`deletion_scheduled_for()`** Twig fn
    — `App\Twig\AccountDeletionExtension`) shows the purge date + a **Cancel
    deletion** button on every authenticated page. Also best-effort-cancels any
    active Stripe subscription immediately (`SubscriptionService::cancelSubscriptionForDeletion`,
    try/catch + log — the existing `customer.subscription.deleted` webhook syncs the
    resulting status, no duplicated sync logic) so a departing user isn't billed
    through the countdown.
  - **`cancelDeletion(User)`** — clears the flag. Also fires automatically if the
    user starts a new Stripe checkout while pending (`SubscriptionController::checkout`)
    — resubscribing is an unambiguous "I changed my mind," so they don't have to
    separately click Cancel first.
  - **`purgeUser(User)`** — the **full account wipe** (every row scoped to the user,
    **its API tokens**, and the user row itself in one transaction; uploaded files are
    unlinked only **after** it commits, so a rolled-back purge leaves a live account
    with its photos intact — `purgeUserData` got the same ordering). ⚠️ `api_token` is
    deleted here, **not** in the shared `deleteUserRows`: "start fresh" keeps tokens.
    Its `user_id` FK has no `ON DELETE`, so until 2026-09-30 any token holder (every
    CGM-wizard user) made the final `DELETE FROM user` fail and the whole purge roll
    back — the cron then retried daily, silently. This is the same
    logic that used to be a private method on `AdminUserController` — extracted here
    so the admin's immediate delete and the grace-period purge cron share one
    implementation. Fixed two pre-existing gaps while extracting it: it now also
    deletes `Supplement.imagePath` and `GoalCheckin.chartPath` files from disk
    (previously orphaned on every delete, admin included) alongside the existing
    meal-photo/favorite-thumbnail cleanup, and best-effort-cancels Stripe first (the
    old admin delete didn't).
  - **`purgeUserData(User)`** — **"delete my data, keep my account"** (the "start
    fresh" option): identical content wipe to `purgeUser` minus the final
    `DELETE FROM user` — login, email/password, timezone/units/tone/notification
    preferences, API tokens, and subscription/comp state are all untouched.
    Additionally resets the four onboarding step timestamps (not `dismissed`) so the
    onboarding overlay reappears and walks the user through a genuine fresh start.
    Does not touch Stripe (they're keeping the account). **Immediate — no grace
    period**: unlike full account deletion, nothing here cuts off login/billing, so
    there's no "log back in to cancel" case to protect; it's a single `confirm()`
    dialog, matching the app's house style for destructive actions elsewhere.
- **`/settings/account`** (`SettingsController::account`/`deleteAccountData`/
  `deleteAccount`/`cancelAccountDeletion`) shows two clearly separated sections —
  "Start fresh" and "Delete my account" — with copy explaining exactly what each does
  and doesn't remove, so the two can't be confused. CSRF ids: `account-delete-data`,
  `account-delete`, `account-cancel-deletion`.
- **Purge cron** — `php bin/console app:purge-deleted-accounts` (`--dry-run`,
  `--now=` for testing) hard-deletes every account whose grace period has elapsed,
  via `UserRepository::findPendingDeletionBefore()`. Per-account try/catch so one bad
  row doesn't block the rest of the batch, but **any failed purge makes the run exit
  `FAILURE`** (like `app:cleanup-meal-uploads`) so a stuck account doesn't retry
  silently forever. **`refresh()`es each user** and re-checks the flag immediately
  before deleting (guards a rare race against a cancel that landed between selection
  and delete — checking the selected entity without the refresh could never see it). Runs **daily** (doesn't need hourly precision like the notification/goal
  cron; crontab hour is **UTC** — see Server timezone under Deployment):
  `30 10 * * * cd /var/www/nouriloop && php8.4 bin/console
  app:purge-deleted-accounts >> /var/www/nouriloop/var/log/purge-deleted-accounts.log 2>&1`.
- **Scheduled sends skip pending-deletion and disabled accounts** —
  `UserRepository::findReceivingScheduledMessages()` (the digest's
  `SendNotificationsCommand`) and `NutritionGoalRepository::findDue()` (joined to `user`)
  both require `deletionRequestedAt IS NULL` **and** `isActive = true`, so someone who
  asked to leave doesn't keep getting digests or goal check-ins during the countdown,
  and an account an admin disabled (which can't even sign in to switch them off) gets
  none. Until 2026-10-07 disabled accounts were still sent both. A re-enabled account's
  overdue goals run at the next hourly pass. Pinned by
  `tests/E2E/ScheduledMessageRecipientsTest`. (`--user=`/`--goal=` on the commands
  still target one account or goal directly, as operator tools.)
- **Admin visibility:** `/admin/users` shows the "Pending deletion" badge (above) and
  a **Cancel deletion** action (`app_admin_user_cancel_deletion`, reuses
  `cancelDeletion()`) for support to undo a user's request if asked; the existing
  **Delete** button still force-purges immediately regardless of a pending grace
  period.
- Covered by `tests/E2E/AccountDeletionTest` (request→banner→cancel, `purgeUserData`
  wipes content + files but keeps the account + resets onboarding, `purgeUser`
  removes the account too — incl. a token-holding user with goals/charts/analysis
  events, leaving other accounts' tokens alone) and
  `tests/E2E/PurgeDeletedAccountsCommandTest` (dry-run, cutoff boundary, no-op when
  nothing's due, a cancel landing mid-run spares the account, a failed purge fails the
  run after finishing the batch).

## Password reset (forgotten password)
Built on **`symfonycasts/reset-password-bundle`**: secure single-use **hashed**
tokens, **1-hour** expiry, request throttling.

- **Routes** (public): `app_forgot_password_request` (`/reset-password`),
  `app_check_email` (`/reset-password/check-email`),
  `app_reset_password` (`/reset-password/reset/{token}`).
- **Code:** `src/Controller/ResetPasswordController.php`,
  `src/Entity/ResetPasswordRequest.php` (+ repository),
  `src/Form/ResetPasswordRequestFormType.php` (email),
  `src/Form/ChangePasswordFormType.php` (new password),
  templates in `templates/reset_password/`.
- **Flow:** request email → (always redirects to check-email, never reveals
  whether the account exists) → email link → set new password → redirect to
  login with a success flash. Reset errors use the standard `error` flash bag.
- **A failed send isn't a 500 (#225, 2026-10-07).** A mail-transport failure is
  caught and `warning`-logged (by user id), and the user lands on the same
  check-email page, with the placeholder token an unknown address gets, so the
  failure reveals nothing about the account (the magic-link posture). The stored
  request is removed, so the page's "Try again" sends a fresh link at once instead
  of being throttled for an hour behind one that never arrived. Covered by
  `tests/E2E/PasswordResetMailFailureTest`.
- The bundle table is `reset_password_request` (migration
  `Version20260608184112`).

## Email delivery (Mailpit dev / Postfix→IONOS prod)
Applies to all mail (password reset, notifications, new-user admin notice, welcome email).

- **Dev:** `mailpit` catcher — `MAILER_DSN=smtp://localhost:1025` (`.env.dev`),
  view at http://localhost:8025 (`brew install mailpit`).
- **Prod:** the VPS provider blocks direct outbound SMTP (port 25), so mail relays
  through **IONOS** as a smarthost. Local **Postfix** is configured as the relay
  (`relayhost = [smtp.ionos.com]:587` + SASL auth in `/etc/postfix/sasl_passwd`),
  and the app hands mail to it with **`MAILER_DSN=sendmail://default`**. SPF/DKIM/
  DMARC all pass (IONOS signs it).
- **From address** is a global default header (`framework.mailer.headers.From`)
  driven by **`MAILER_FROM`** (`.env` / `.env.local`) — no per-message `->from()`.
  It **must** equal the authenticated IONOS mailbox — or an alias of it — or IONOS
  rejects it. The public contact address is **`info@nouriloop.com`** (an alias of the
  `john@nouriloop.com` IONOS mailbox, created 2026-07-13): the repo's `.env` default
  and every public-page contact mention now use it, **except the welcome email's
  letter sign-off, which deliberately stays `john@nouriloop.com`** (it's a personal
  note from John — `WelcomeEmailTest` pins this). **`info@` is an alias, not a
  mailbox: SMTP auth is and stays `john@nouriloop.com`** — the Postfix SASL
  credentials (`/etc/postfix/sasl_passwd` on the VPS) never change; only the visible
  From header does. **Both directions are confirmed working (2026-07-13):** inbound —
  mail sent to info@ lands in the john@ mailbox — and outbound — after the v1.7.0
  deploy set prod's `MAILER_FROM` to info@, a live password-reset send arrived
  cleanly, so IONOS accepts the alias as a From identity. Prod sends as
  **`info@nouriloop.com`**.
  SPF/DKIM/DMARC pass for `nouriloop.com`. (History: the sender domain was
  `@johnchouser.com` until the IONOS `nouriloop.com` mailbox existed — cut over
  2026-06-21; sender was `john@nouriloop.com` until the info@ alias, 2026-07-13.)
- **Reply-To** is a second global header (`framework.mailer.headers.Reply-To` =
  **`noreply@nouriloop.com`**), unlike `From` it need not be the authenticated
  mailbox — it just steers replies to an unmonitored box so they don't land in the
  `From` mailbox. Plain literal (same in all envs); no env var.
- Mailer/SMS go through **Messenger** but are intentionally **left unrouted in
  `config/packages/messenger.yaml`**, so they send **synchronously in-process in
  all environments** — no `messenger:consume` worker is required. (The Flex recipe's
  `ChatMessage: async` route was removed 2026-09-30 for the same reason: nothing sends
  chat messages, and with no worker anywhere one would have queued forever.)
- Password resets are **throttled** (~1h) by reset-password-bundle; when testing,
  clear `reset_password_request` or repeats are silently dropped (302, no email).

## Forms & CSRF
- **dev/prod use Symfony's _stateless_ CSRF, enforced by the same-origin check — NOT a
  JS cookie.** `config/packages/csrf.yaml` marks the `submit`/`authenticate`/`logout`
  token ids stateless under `when@dev`/`when@prod`, so they're validated by Symfony's
  `SameOriginCsrfTokenManager`. The hidden field renders the literal placeholder
  `value="csrf-token"` and **stays that way** — what actually protects the POST is the
  browser's `Origin`/`Referer`/`Sec-Fetch-Site` headers: a same-origin request passes, a
  cross-site or origin-less one is rejected with "Invalid CSRF token." (Empirically
  verified: same-origin `Origin` → passes even with the field omitted; cross-site or
  missing `Origin`+`Referer` → rejected.) This blocks the real CSRF threat (a cross-site
  auto-submitting form) without any client JS.
- **The double-submit-cookie path is dormant.** Symfony ships a `csrf-protection` Stimulus
  controller (`assets/controllers/csrf_protection_controller.js`) that *would* generate a
  random token + matching cookie as a fallback for requests that can't be proven
  same-origin by headers — **but this app never renders `importmap()`, so Stimulus never
  loads and that controller never runs** (see the inline-JS note under Help ▸ NL assistant;
  `assets/controllers/*` are all inert). The only practical cost: a genuinely same-origin
  POST that arrives with **neither** `Origin` **nor** `Referer` (rare — real browsers send
  at least `Origin` on form POSTs; some hardened privacy setups strip both) is rejected,
  because the JS fallback that would have covered it isn't loaded.
- **Non-browser clients (curl/scripts) must send an `Origin` (or `Referer`) header matching
  the host** to POST successfully — there is no cookie/token to replicate, just the
  same-origin signal.
- **The `test` env uses stateful CSRF instead.** The stateless ids are scoped to
  `when@dev`/`when@prod` only, so in `test` they fall back to server-rendered, session-backed
  tokens — the browser E2E suite then doesn't depend on request headers or any JS. CSRF
  stays on in every env.

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
  SMS `sms_digest`, check-in email and SMS `checkin_delivery`). Settings asks
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
  the `sms_digest` entitlement (Loop and up)** — see Goal & notification tiering under
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

## Health metrics (import + ingestion API)
Seven metric types (weight, blood glucose, BP systolic/diastolic, heart rate, body
fat %, **steps**) plus five sleep stages, stored in `health_metric`. Dedup is enforced by the
**`uq_health_metric`** unique constraint (`user_id, metric_type, recorded_at`) via
**`INSERT IGNORE`**, so every ingestion path is safely re-runnable.

- **One way in — `App\Service\HealthMetricIngestion` (2026-10-06).** Every source
  (Apple Health XML, the health-export JSON apps, the ingestion API, CSV) parses its format
  into `App\Dto\Reading`s (type, value **in the source's own unit**, that unit or `''`,
  the source's wall-clock time, source name) and hands them to
  `ingest(User, iterable<Reading>, ?UnitSystem)`. Ingestion owns everything about
  storing them: canonical units (`HealthUnitNormalizer`), the stored precision
  (`HealthUnitNormalizer::round()`: steps whole, everything else 2 decimals; before
  this the XML import and the API stored whatever was sent), the 100-character source
  cap (characters, not bytes), and the wall-clock format. It also **refuses implausible
  readings for every source** (counted as skipped, with a "Skipped {type} at {time}:
  {reason}." message): a negative value, a zero for anything but steps (a zero-step day
  is real; a zero weight or heart rate is a missing reading), a sleep row over 960
  minutes (16 h), and a time more than a day past the user's local now. Before
  2026-10-06 the only bound anywhere was the XML import's 0 < segment ≤ 720 minutes
  (skipped silently), and the API took anything, negative sleep included; the XML cap
  rose to 960 because a Health Export in-bed total can pass 12 h on a long night.
  **"Skipped" means a reading that should have been stored and wasn't**: a bad value or
  date, an implausible reading, or a duplicate. Data ignored on purpose is not a skip:
  types NouriLoop doesn't track, workouts, Awake sleep segments, and steps in an Apple
  Health export. (Until 2026-10-06 the XML import counted all of those, so a typical
  export reported thousands of "skipped" records.) In a CSV, a type or column NouriLoop
  doesn't track and a blank columnar cell are ignored, while a value that isn't a
  number is a skip reported with its row (and column); before, the row format skipped
  untracked types silently, and the columnar format dropped bad cells uncounted. The API still reports an unknown
  `type` as a skip with a reason, because there it is a malformed request, not extra data.
  `HealthMetricWriter` is now its private batch insert
  (500 rows per `INSERT IGNORE`). Readings are consumed lazily, so a huge import
  streams (never materialised in memory). The `UnitSystem` argument is the unit an
  **unlabelled** weight is assumed to be in, and only a source with no units of its own
  passes it (CSV). The importers are parsers and nothing more. ⚠️ CSV used to skip the
  writer with its own one-row-per-statement `INSERT IGNORE`, and counted a duplicate
  row as **imported**; since it moved onto ingestion it batches like every other source
  and a duplicate is a skip. Covered by `tests/Service/HealthMetricIngestionTest` and
  the parser tests (`AppleHealthXmlImporterTest`, `HealthMetricCsvImporterTest`, the
  two JSON importer tests, `HealthMetricApiParserTest`).

- **Web import** (`HealthMetricsController::import`, `/health-metrics/import`):
  **upload-only** — Apple Health XML (`AppleHealthXmlImporter`), **health-export JSON**
  (any registered `HealthJsonImporter`, routed by `HealthJsonImportResolver`), or CSV
  (`HealthMetricCsvImporter`). ⚠️ It's a hand-rolled multipart form, not the Form
  component, so it carries its own CSRF token (`health-import`, checked first; a miss
  flashes `common.session_expired` and re-renders) — until 2026-09-30 it was the only
  state-changing POST with no CSRF check at all. The broad format is auto-detected by extension/content
  (`detectFormat`), and a JSON file's specific app is auto-detected with an optional
  **"Source app"** dropdown override (`json_app`). The upload is bounded by nginx
  `client_max_body_size` (prod: 100M) and PHP `upload_max_filesize`/`post_max_size`;
  a real `export.xml` (often 100s of MB) **won't fit** — it's silently rejected at
  nginx (413, before PHP), so app logs stay empty. Use the API + sync script below
  for large histories. (The old **"local file path"** option — which read a file off
  the *server* filesystem, a single-user local-Mac convenience — was **removed**: in
  a multi-user deployment it let any account read arbitrary server-readable files.
  Import runs with a finite `memory_limit` of 512M, not unlimited.)
- **Ingestion API** (`POST /api/health-metrics`, `HealthMetricApiController`):
  bearer-token JSON for mobile apps / scripts. Body `{ "metrics": [ {type, value,
  unit?, recordedAt, source?}, … ] }`; `type` ∈ `HealthMetricType` values,
  `recordedAt` ISO-8601, optional `unit` (see Unit normalization below). `HealthMetricApiParser` validates per
  record (bad rows skipped with a reason, not a whole-batch failure), max 1000/req.
  Returns `{imported, skipped, errors}`.
- **Health-export JSON ingestion** (`POST /api/health-metrics/health-export`,
  `HealthMetricApiController::healthExport`): accepts a health-export app's JSON
  **verbatim** (5 MB cap). `HealthJsonImportResolver` picks the importer by
  auto-detection, or by an explicit `?app=<key>` override (the CLI's `--app=`); on no
  match it 422s asking for the app. Returns `{imported, skipped, errors}`.
- **Two ways to feed this endpoint:** (1) the **sync CLI/cron** posts an exported
  file (works for every supported app via auto-detect — the portable path); (2)
  **Health Auto Export's built-in REST API automation** POSTs straight from the phone
  — no Mac/cron. Configure the automation with URL
  `https://nouriloop.com/api/health-metrics/health-export`, header
  `Authorization: Bearer <token>`, **JSON** format, on a schedule. **Summarize Data is the
  lever that matters for glucose: OFF sends every reading** (the wizard's documented working
  config, verified against the live "NouriLoop Blood Glucose" automation 2026-07-03: JSON,
  v2, Date Range Default, Summarize off, Batch Requests on, once-daily cadence); **ON**
  collapses to grouped summaries (the Time Grouping row only appears while it's on) — those
  import fine but break postprandial curves (see the CGM data caveat under Postprandial
  glucose, and the Connect your CGM wizard, which detects exactly this misconfiguration). The importer also accepts disaggregated heart-rate (`Avg`→`qty`
  fallback). HAE's "Batch Requests" splits large payloads into several POSTs — each is
  a valid envelope and dedup makes overlaps safe.
- **Pluggable importers** — each app is one `HealthJsonImporter` implementation
  (`key()`/`label()`/`supports()`/`import()`), auto-tagged via `_instanceof`
  (`app.health_json_importer` in `services.yaml`) and collected by the resolver, so
  **adding an app is a single drop-in class** with no other changes. Each yields
  `Reading`s to `HealthMetricIngestion`, which gives every importer units, rounding,
  dedup and batching for free.
  - **`HealthAutoExportJsonImporter`** (`key: health-auto-export`) — the "Health Auto
    Export – JSON+CSV" app: `data.metrics[]` list, each `{name, units, data[]}`. Maps
    `blood_glucose` (mg/dL; mmol/L→mg/dL), `weight_body_mass` (lb→kg), `heart_rate`
    (uses `Avg`; `resting_heart_rate` ignored to avoid a same-timestamp collision),
    `step_count`→steps, `blood_pressure_systolic`/`_diastolic`, and `sleep_analysis`
    (hours→minutes: inBed + Core/Deep/REM, awake/legacy-asleep excluded). Dates are
    local-with-offset → wall-clock stored (offset dropped, like the XML importer);
    sleep `recorded_at` is `sleepStart`. Workouts + untracked metrics ignored.
  - **`HealthExportJsonImporter`** (`key: health-export`) — the "Health Export" iPhone
    app: root `exportInfo` envelope + camelCase metric keys, each a list of
    `{date, unit, value}` daily records (`sleepTime` carries per-stage minutes). Maps
    `weight` (lb→kg via the record's `unit`), `bodyFat`→body fat % (0–1 fraction scaled
    up), `restingHeartRate`→heart rate, `stepCount`→steps, and `sleepTime.stages`→
    SleepInBed (sum incl. awake) + Core/Deep/REM (minutes; awake excluded, no
    synthesized Asleep). Dates are local, date-only → stored at **noon local** for a
    stable dedup key. Unmapped types (calories, BMI, VO₂max, dietary — and **no blood
    glucose/pressure**, absent from this exporter) are ignored.
- **Auth:** a stateless **`^/api`** firewall (`config/packages/security.yaml`, listed
  before `main`) using Symfony's `access_token` authenticator + `ApiTokenHandler`,
  which hashes the bearer token and looks it up. **`ApiToken`** (per-user, revocable)
  stores only the **SHA-256 hash**; the plaintext (`nutri_<64 hex>`) is shown once.
  Manage at **Settings ▸ API tokens** (`/settings/api-tokens`, `SettingsController`)
  — create/copy-once/revoke; `lastUsedAt` is stamped on auth (≤1 write/min).
- **Sync CLI** (`scripts/apple-health-sync.php`): standalone PHP CLI (no app deps),
  auto-detects the input format from `--file`. **XML** → streams `export.xml` and
  POSTs batches to `/api/health-metrics` (mirrors the XML importer's mapping,
  `--batch` ≤1000), bypassing the upload limit. **JSON** → posts the Health Export
  file **verbatim** to `…/health-export` (server owns the mapping); `--file` may be a
  directory, in which case the newest `*.json` is picked — handy for a nightly cron
  pointed at the app's Dropbox/iCloud export folder. `--app=<key>` forces a source app
  (else auto-detected). Common flags: `--url= --token=` (or `NOURILOOP_TOKEN`, with
  `NUTRILOG_TOKEN` still accepted as a fallback), `--dry-run`. Server dedups, so
  re-running is safe.
- **Display** (`HealthMetricsController::index`) and Trends use bucketed aggregate
  queries (`HealthMetricRepository::getAggregates`); `uq_health_metric` doubles as their
  covering index. **Admin user-delete** removes `health_metric` rows too (FK-safe order).
- **Per-type bucket summary (`App\Enum\MetricSummary`, via `HealthMetricType::summary()`).**
  `getAggregates` summarizes each type per its `MetricSummary` rather than always
  averaging: **Steps → `Total`** (SUM — a *daily total*, correct even with multiple
  samples/day; min/max collapse so no range band — fixes steps previously shown as an
  average), **weight / body fat → `Reading`** (the day's measurement), **glucose / heart
  rate / blood pressure → `Average`** (mean + a meaningful min/max range). The same enum
  drives the card sublabel (`bucketLabel()` → "daily totals" / "daily readings" / "daily
  averages, range shaded"), so the number and its label can't drift. Because Trends shares
  `getAggregates`, its **Steps** Insights tile now reads the daily total too.
- **Sleep storage & nightly totals.** Sleep is stored as **per-segment rows in minutes**
  (one `value` per stage; `recorded_at` = segment start), separate from the displayed
  total. `HealthMetricRepository::getSleepNightlyData` buckets segments into nights on a
  **noon boundary** (`recorded_at − 12h`), sums by **tier priority stages
  (core+deep+rem) → asleep → in-bed** via `COALESCE` (so a night with both granular
  stages *and* an InBed record isn't double-counted), `/60` → hours, dropping nights
  < 0.5 h. Surfaced on Trends only.
- **Unit normalization — `App\Service\HealthUnitNormalizer` (2026-09-30).** Every
  ingestion path (web XML/CSV/JSON, the API, and so the sync CLI) converts to the
  canonical storage units through this one static helper: weight lb/lbs/pounds → kg;
  glucose `mmol…` → mg/dL (×18.0182 — **prefix** match, because Apple's `export.xml`
  labels it `mmol<180.1558800000541>/L`); an **unlabelled** glucose value below
  `GLUCOSE_MMOL_CEILING` (35) is taken as mmol/L (real mg/dL readings are ~40–600,
  mmol/L ~2–33); body fat ≤ 1.5 is a 0–1 fraction and is scaled to percent (Apple /
  HealthKit report fractions — the XML path stored them raw before). Before this,
  each importer had its own copy and the API normalizer + XML importer converted only
  lb, so **mmol/L glucose was stored as mg/dL**, poisoning postprandial curves and
  rankings. `UnitConverter` is the display-side inverse, sharing the factors.
  - **CSV** has no units of its own, so in order: a `unit` column (row format), a unit
    in the column header (`weight (lb)`, `glucose_mmol`, `weight lbs`, …; underscores
    in headers are now read as spaces, so `blood_glucose`/`heart_rate` columns map too),
    else the user's `UnitSystem` for an unlabelled **weight** (imperial → lb — the
    old behaviour stored a US user's 180 lb as 180 kg). CSV also maps **`steps`** /
    `step count` columns now.
  - ⚠️ **The Apple XML importer deliberately still skips steps.** `export.xml` holds
    per-device step *samples* (iPhone and Watch both record the same walk), and Steps
    are summarized by SUM — importing them would double-count every Watch wearer's
    day. Apple dedups across sources itself; we'd have to re-implement its source
    priority. HAE/Health Export send per-day totals, which is why their steps are fine.
  - Covered by `tests/Service/HealthUnitNormalizerTest`, a mmol case in
    `HealthMetricApiParserTest`, and `tests/E2E/HealthImportUnitsTest` (CSV unit
    column / header units / imperial default / steps; XML Apple mmol string + body-fat
    fraction; the repair command below).
- **Data repair — `app:fix-glucose-units`** (`--user=<email>`, `--apply`): repairs
  glucose rows stored in mmol/L as if mg/dL before the fix above. **Reports by default**
  (per-user row count, min/max, sources); `--apply` multiplies rows in
  [`MIN_CONVERTIBLE` 1.95, 35) by 18.0182. The 1.95 floor keeps `--apply` idempotent (a
  converted row always lands ≥ 35, so a second run finds nothing); rows below it are
  implausible in either unit and are reported, never touched. John's call (2026-09-30):
  a report-then-apply command, run on prod after reviewing the report. ⚠️ Body-fat rows
  the XML path stored as fractions (e.g. 0.25) are **not** repaired by it.
- **Data repair — `app:fix-sleep-outliers <email>`** (`--threshold=10` hours, `--dry-run`):
  fixes implausibly long nights left by early mis-configured imports. Computes the user's
  **average nightly hours from the nights *below* the threshold**, then for each
  over-threshold night **deletes that night's sleep segments** and writes a single
  `sleep_asleep` row (`source='corrected-avg'`) equal to the average at the night's onset —
  which is exactly what `getSleepNightlyData` reads back. Transactional; errors out if
  there's no normal night to average from. Covered by `tests/E2E/FixSleepOutliersTest`
  (in-process `WebTestCase` + `CommandTester`: the **apply** path replaces the outlier &
  leaves normal nights, dry-run writes nothing, all-bad → failure).

## Connect your CGM wizard (`/connect-cgm`)
A guided "Connect your Lingo/Stelo" setup (roadmap item elevated 2026-07-03: CGM correlation
is the #1 differentiator, but the HAE + token + REST-automation setup was a conversion cliff
for the 45–70 audience). **One long page, all steps visible** (`ConnectCgmController`,
`templates/connect_cgm/index.html.twig`): intro (what you need, honest about HAE possibly
needing its own paid tier) → install HAE (App Store link + QR) → create token → configure the
REST automation (exact values with copy buttons) → send a first export → **live verification
ladder**. **Available to all tiers** — importing glucose is free; correlation features keep
their existing Loop+ gates (Free users get an upsell on success). No new entity/migration —
everything is derived. Fully deterministic, **no Claude call**.

  The step-3 endpoint URL is the **hardcoded production**
  `https://nouriloop.com/api/health-metrics/health-export` (not `url()`-derived — it's the
  only address a user should paste into HAE, and `url()` burned the dev/test host into local
  renders and the committed help screenshot).
- **Routes** (catch-all `^/` auth, no security.yaml change): `GET /connect-cgm`
  (`app_connect_cgm`), `POST /connect-cgm/token` (`app_connect_cgm_token`, CSRF
  `connect-cgm-token` — mints an `ApiToken` named `ConnectCgmController::TOKEN_NAME`
  ("Health Auto Export") via `ApiTokenManager::create` and reuses Settings' one-shot
  `api_token_new` flash; the plaintext renders once in the step-2 copy card **and** woven into
  step 3's `Bearer …` copy value; a return visit gets a "just create a fresh one" card), and
  `GET /connect-cgm/status` (`app_connect_cgm_status`, JSON — polled every 10 s by inline
  vanilla JS, paused on `document.hidden`, stopped on success; deliberately un-rate-limited).
- **`CgmSetupStatusService::check(User) → CgmSetupStatus` DTO** — the stateless ladder:
  `no_token` → `token_unused` → `waiting` (a token's `lastUsedAt` within
  `RECENT_WINDOW_HOURS` = 48 h of server now — `ApiTokenHandler` stamps it on every bearer
  auth, so it means "the phone reached us") → `sparse`/`dense` from glucose recency + density
  (`HealthMetricRepository::findRecentGlucoseTimestamps`, window base in the user's frame via
  `UserTime`). **Dense** = ≥ `DENSE_MIN_READINGS` (12) distinct recent timestamps AND median
  gap ≤ `DENSE_MAX_MEDIAN_GAP_MIN` (20 min) — separates a real per-reading CGM feed (~5-min
  cadence) from HAE's Summarize-Data-on daily summaries (`sparse`, the wizard's amber "turn
  Summarize Data off" message) and from hourly summaries (also `sparse`; curves need
  `MIN_READINGS_FOR_CURVE` = 6 in 180 min). Glucose evidence trumps the token rungs (a
  file-upload user still completes); recency windows mean stale/mock data can't fake success.
  Reaching `dense` marks the onboarding `health` step from the status GET — an idempotent
  materialization of derived truth (`markStep` no-ops once onboarding is resolved).
- **Entry points:** the onboarding health step's primary CTA (`then=connect`), a
  no-glucose callout on `/health-metrics`, the Glucose Insights empty state + Loop+ upsell,
  a hint on Settings ▸ API tokens, and the public `connect-cgm` help topic (which is the
  prose twin of the wizard — deliberately duplicated copy, see both file headers).
- **Images:** the HAE iPhone screenshots (`public/images/help/connect-cgm-hae-*.png`) and the
  App Store QR are **hand-captured/committed, not regenerated** by
  `app:capture-help-screenshots` (which does regenerate the topic's lead shot of the wizard
  itself); every `<img>` self-hides via `onerror` until its PNG lands.
- Covered by `tests/E2E/ConnectCgmTest` (in-process `WebTestCase`: anonymous redirect, all
  steps render, token copy-once + step-3 weave, the full ladder incl. stale-glucose,
  Day-aggregation and hourly → `sparse`, dense → success + onboarding mark + idempotence,
  tokenless dense) and an `OnboardingTest` case for the `then=connect` CTA.

## Meal logging (photo → Claude → review)
- **Upload** (`MealController::log`, `/meal/log`): **1–3 photos of ONE meal**
  (`MealEntryFormType::MAX_PHOTOS`, `multiple` file field — so the dish + its
  nutrition label go through **one** Claude call instead of an upload + re-analyze
  round; field name is now `meal_entry_form[image][]`), each HEIC-converted →
  `FoodAnalysisService` (Claude vision) → `AnalysisResult` (nutrition + ingredients
  + confidence + questions). The **first** photo is the meal's thumbnail; photos
  2..n join the review's `extra_web_paths` (analysis aids, deleted on save like
  refine-round extras). EXIF timestamp (of the first photo) sets `loggedAt`, but the
  **Confirm & Refine** screen exposes an editable date/time (`logged_at`, future
  blocked) so a meal can be **logged retroactively** — `MealController::parseLoggedAt`
  applies it on save, falling back to the EXIF/now default. Stored filenames (the
  meal photo and the review's extra photos) use a **random stem** —
  `bin2hex(random_bytes(16))`, not `uniqid()` — because `public/uploads/` is
  web-served without auth, so the name's entropy is the only thing keeping a
  stranger from fetching someone's food photos (same pattern as `FavoriteManager`;
  supplements + goal charts match).
- **Staged photos go through `App\Service\PhotoStaging` (2026-10-06).** Every upload
  for a draft or re-analysis proposal — the log form, the retry, the clarify extra photo,
  a re-analysis, a recipe scan, a supplement scan — is staged by
  `stage(files, StagingPurpose)`: validated (20 MB, the image MIME list), written under
  the purpose's prefix (`meal-`, `meal-extra-`, `meal-reanalysis-`, `recipe-`, `supp-`)
  plus the random stem, HEIC-converted, and returned as `StagedPhoto`s (path + web path).
  **All or nothing:** if any file in a batch fails, the files already written are removed
  and it throws `PhotoStagingFailed`. That exception keeps the two causes apart: a file
  that isn't an acceptable image carries the validator's translated message (`invalidFileMessage()`),
  which the flash shows; only a failed HEIC conversion shows `common.heic_failed`. (Until
  2026-10-06 an invalid extra or re-analysis photo was reported as a HEIC failure.) Any
  other error, such as an unwritable directory, is rethrown after the rollback.
  `discard()` is the one delete for staged files, and
  `webPath()`/`path()` convert between the two forms the session still stores. It replaced
  five copies of the upload loop and the `storeExtraPhoto`/`cleanupFiles`/`dropPaths`
  helpers. Covered by `tests/Service/PhotoStagingTest`.
- **Drafts are typed (`src/Draft/`, 2026-10-06).** Everything a user is part-way
  through lives in the session as a typed value implementing `SessionDraft`:
  `MealDraft` (the Confirm & Refine review, key `meal_analysis`), `RetryParking`
  (`meal_analysis_retry`), `ReanalysisProposal` (`meal_reanalysis`), `RecipeDraft`
  (`recipe_import`) and `SupplementDraft` (`supplement_scan`). `DraftSession` is the
  only thing that touches those keys: `load()` (a malformed or stale entry comes back
  null and is dropped), `store()` and `clear(keep:)`. It also owns their staged photos:
  **storing a draft deletes the photos the draft it replaces held that it doesn't**,
  so starting a new log, scan or re-analysis over an open one cleans up at once
  instead of leaving the old photos for the 48-hour sweep, and `clear()` deletes them
  all except those handed on to a saved record. ⚠️ The session still holds **plain
  arrays under the same keys and array keys as before**, not serialized objects, so a
  draft open across a deploy still loads; keep `toArray()` key names stable, or make
  `fromArray()` accept the old ones. A `MealDraft` whose `logged_at` doesn't parse is
  refused: the old save's `createFromFormat(...) ?? now` fallback never fired
  (`createFromFormat()` returns false) and would have passed false to
  `setLoggedAt()`. Covered by `tests/Draft/`.
- **Photo-free logging ("describe your meal", 2026-07-21):** the log page's "No
  photo? Describe your meal instead" toggle reveals an optional description
  textarea (≤1000 chars); the photo becomes optional and the controller requires
  **either** photos **or** text (`meal.log.need_photo_or_text` form error).
  Text-only submissions run **`FoodAnalysisService::analyzeDescription`** — the
  text-only sibling of `analyzeImage` sharing the whole prompt via the private
  `buildPrompt()`/`requestAnalysis()` split (same JSON contract, same locale/notes/
  context handling, same Free-tier gate, cheaper call) — and land on the same
  Confirm & Refine review, which shows the user's own words (`meal.clarify.
  described_as` card) alongside/instead of the photo. Session state carries
  `describe`; re-analysis rounds re-run `analyzeDescription`, and any flow with
  both a photo AND a description folds the description into the notes via
  `MealController::combinedNotes` — on the **initial** photo analysis, on every
  re-analyze, and through the retry state (Copilot catch on PR #164: the first
  estimate must not silently ignore a description submitted with a photo;
  pinned by a `MealLogPathsTest` case that puts the failure trigger in the
  description). Saved photo-free meals have
  `imagePath = null` (already nullable; dashboard/show templates already guard it;
  `MealDuplicateChecker` no-ops on null). A failed text-only analysis just
  re-renders the form (nothing on disk to park — the retry-card flow is
  photo-only).
- **Free-tier allowance counter:** the log page shows Free users an ICU-plural
  "N of {limit} free AI analyses left this month" (`meal.log.allowance_left` —
  plural-branched in every catalog per the countable-message rule; fr/ru needed
  real agreement branches) via `AnalysisAllowance::remaining()` and
  `FREE_MONTHLY_LIMIT` (amber + a pricing link at ≤3; read directly since 2026-10-06 —
  `FoodAnalysisService`'s pass-through alias and method were deleted) and
  `MealController::renderLog`, the shared render helper for every log-page path —
  so the limit is visible before it's hit rather than a surprise redirect.
  Paid/comped users see nothing.
- **Camera capture + client-side downscale (mobile):** a `d-md-none` "Take a photo
  now" button triggers a hidden `capture="environment"` input; captured shots are
  **downscaled client-side** (canvas, longest edge 2000px, JPEG 0.85, only files
  > 3 MB) before merging into the main input. Independently, the **server
  downscales oversized images for the API request only** (`App\Service\ApiImageEncoder`
  — GD, > `MAX_BYTES` = 4 MB re-encodes to JPEG q85, longest edge ≤ 2000 px; the stored
  original is untouched; one shared helper since 2026-09-30, and since 2026-10-06 called
  only from `ClaudeClient::completeWithImages`, which every vision analysis goes through —
  it was a private method copied into the meal and recipe analyzers): 3 × 20 MB uploads
  would blow past the Messages API's ~32 MB request cap once base64-encoded
  (Copilot catch on PR #164), and Claude gains nothing from >2000 px food
  photos anyway. Undecodable/no-win images are sent as-is. Pinned by an
  oversized-noise-JPEG request-capture case in `ClaudeClientTest`. ⚠️ Downscaling is **deliberately
  limited to camera captures**: canvas strips EXIF, and library photos' EXIF dates
  drive the intentional backdating feature (see Meal EXIF default below) — a fresh
  camera shot's EXIF is "now", which is the fallback anyway. Chrome can't decode
  HEIC in canvas — the code falls back to uploading the original (server converts).
- **Confirm & Refine is progressively disclosed (2026-07-21):** the confident
  common case reads photo → nutrition + servings (now the FIRST card) → Save. The
  secondary sections collapse to slim rows (`.js-toggle` + `d-none`, hidden inputs
  still submit): the **ingredients table** collapses to an ICU-plural count row
  (auto-open in refinement mode = not confident AND questions remain), the
  **extra-photo card** collapses unless extras exist or a question mentions a
  label/packaging/brand stem — matched in **every supported language** (questions
  come back localized, so the stem list in `clarify.html.twig` covers
  en/es/de/fr/ru/hi; a heuristic where a miss just means one extra tap, not a
  contract — Copilot catch on PR #164), the **date card** collapses to a one-line
  "Logging for {datetime} · change" summary (auto-open when backdated — and the
  `#backdate-warning` deliberately sits OUTSIDE the collapsible body so it's
  visible while the row is collapsed; `MealFlowTest` depends on that), the
  **favorite-name field** hides behind a "Name this favorite (optional)" link
  (the default name applies otherwise), and **prior Q&A** is a native `<details>`.
  ⚠️ Form-widget ids in these templates must be overridden **top-level**
  (`form_widget(f, {id: 'x'})`) — `attr.id` is silently ignored (this had left the
  log page's drop-zone preview JS dead; same trap as the register timezone field).
- **A nutrient Claude couldn't put a number on is no estimate (2026-10-06).** A
  non-numeric value in the reply (`"~20"`, `"unknown"`) maps to **null** — hidden on the
  meal page, skipped by goals and averages — not 0.0. Meals used to store 0.0 (a real
  "0.0 g" row that dragged goal averages down) while recipe imports already used null;
  the meal side was brought in line. Pinned by `FoodAnalysisServiceTest`. The rule
  can't drift again because the reply is read in **one** place for every analysis:
  `NutritionData::nutrientsFromClaudeJson()` (snake_case reply key → field, derived
  from the entity's field list, numeric values only) and `fromClaudeJson()` (a new
  row + confidence) serve the meal and recipe analyses, the supplement scan builds its
  nutrient map from the same method, and `IngredientList::fromClaudeJson()` normalises
  the ingredient list for meals and recipes (`tests/Entity/NutritionDataClaudeJsonTest`,
  `tests/Service/IngredientListTest`).
- **Meal notes reach the AI** (fixed 2026-07-21 — they were stored but never sent):
  `FoodAnalysisService::analyzeImage(User, $paths, $context, ?$notes)` prepends a
  block telling Claude to treat the user's notes as **authoritative over visual
  guesses** (dish name, restaurant, ingredients, preparation, serving size). Passed
  from the initial `log()` analysis **and** every `clarify()` re-analyze round (the
  notes live in the session state). Notes are **bounded** since they feed a billable
  prompt: `FoodAnalysisService::NOTES_MAX_LENGTH` (1000) backs both the form's
  `Length` constraint/`maxlength` attr and a defensive `mb_substr` in the service.
  The form placeholder now sets that expectation
  ("Tell the AI anything the photo doesn't show…"). Pinned by prompt-capture tests in
  `FoodAnalysisServiceTest` (notes present / blank leaves the prompt untouched).
- **Meal type preselects by time of day** — `MealType::defaultForHour(int)` (4–10
  breakfast, 11–15 lunch, 16–21 dinner, else snack) applied in `MealController::log`
  from the user's `UserTime` local hour; a POST overrides it as usual. Unit-tested in
  `tests/Enum/MealTypeTest`. **The favorites picker preselects the same way**
  (`FavoriteController::index` passes `defaultMealType`; fixed 2026-07-21 with Wave 3 —
  it had been left defaulting to Breakfast when the log form was fixed in Wave 1;
  covered by `tests/E2E/FavoritePickerDefaultTest`, which uses two users 12 h apart so
  a hardcoded default can't pass).
- **Analysis failure keeps the upload + offers one-click retry** (2026-07-21;
  previously the photo was deleted and the user had to re-pick the file): a generic
  Claude failure in `log()` parks `{image_path, image_web_path, meal_type, notes}` in
  the session (a `RetryParking` draft) — PRG back to `/meal/log`, which renders a retry card
  (parked thumbnail + **Retry analysis** button → `POST /meal/log/retry`, CSRF
  `meal-retry`). Retry re-runs the analysis with the kept (already HEIC-converted)
  photo + original type/notes and proceeds to the normal Confirm & Refine on success.
  Orphan-safety: parking a new failure deletes a previously parked photo, a fresh
  successful analysis clears the parked state, and stale state whose file vanished
  self-clears (`RetryParking::fromArray()` refuses it). The photo-limit path still
  deletes + redirects to `/pricing` (no point parking an analysis that can't run).
  **Abandonment backstop** (Copilot catch on PR #163): a user who walks away — or
  a session that expires — leaves the parked photo (and, pre-existing, any
  in-review upload) with no reference outside the session, so
  **`app:cleanup-meal-uploads`** (`CleanupMealUploadsCommand`, `--dry-run`,
  `--older-than-hours=48`) sweeps `public/uploads/meals` files that are older
  than the threshold AND unreferenced by any `meal_entry.image_path`; run daily
  from cron (see Deployment). A file that **fails to unlink** (ACL regression)
  is surfaced and fails the run (`Command::FAILURE`) rather than being counted
  as deleted — these are privacy-sensitive photos, so the cron log must not
  claim removals that didn't happen. ⚠️ Its test constructs the command with a scratch
  dir, never the container's `%uploads_dir%` — that parameter is the real
  dev-shared uploads dir even in the `test` env (only the DB name gets `_test`),
  and the referenced-set comes from the test DB, so a container-wired run sweeps
  the dev environment's own files (happened once).
  Covered by `tests/E2E/MealAnalysisRetryTest` (failure→park→retry-succeeds,
  still-failing keeps the card, fresh success drops the parked file) — failures are
  simulated via **`FakeAnthropicResponder`'s trigger markers in the meal notes**
  (`FAIL_ONCE`/`FAIL_ALWAYS` consts; notes flow into the prompt, so these tests also
  prove the notes wiring end-to-end). Since the multi-photo change the retry state
  carries `image_paths` (all parked photos, retried together).
- The Wave-2 paths are covered by `tests/E2E/MealLogPathsTest` (in-process: two
  photos → one analysis + extra thumbnail on review + extra dropped on save;
  described meal saves with null imagePath and its page renders; neither photo nor
  text rejected; Free user sees the allowance counter, comped user doesn't) and
  `analyzeDescription` prompt-capture cases in `FoodAnalysisServiceTest`.
- **Reused/older photos — backdate warning + duplicate guard.** The EXIF default is
  deliberate (lets several days of meals be logged in one batch, e.g. while
  traveling) — but a **future** EXIF time (camera clock set ahead, wrong zone) falls
  back to now (`MealController::exifOrNow`, 2026-09-30; before, it stuck, because the
  save path rejects a future posted time and keeps the default —
  `tests/E2E/MealExifDefaultTest`). So to stop a *silent* backdate: when the prefilled `logged_at` isn't
  today, the Confirm screen shows a prominent "this will be logged for `<date>`, not
  today" warning (server-rendered in `clarify.html.twig` + kept live by JS as the
  date field changes). And **`MealDuplicateChecker`** (called from `saveFromState`)
  flags a likely duplicate: the **same photo** — compared by **md5 of the stored
  image**, since each save copies to a unique filename so paths never match — already
  logged at the **same `loggedAt`** for that user. On a match it logs a `warning`
  (channel `app`, so it persists via the prod `warnings` handler) **and** the saved
  meal's page renders a "Possible duplicate" callout with a one-click **Delete Entry**
  action (`templates/meal/show.html.twig`, driven by a dedicated `meal_duplicate`
  flash). Non-blocking — the meal still saves; the user decides. (`MealDuplicateChecker`
  takes `$uploadsDir` from `services.yaml`; the photo-save path only, not log-from-favorite.)
- **Every meal** then routes through the **Confirm & Refine** review
  (`/meal/clarify`, `templates/meal/clarify.html.twig`) — not just low-confidence
  ones. The estimate (nutrition + ingredients) is stored in the **session**.
- **Portion multiplier:** the review has a "how many servings did you eat?" control.
  **Save** scales the stored nutrition **and** numeric ingredient amounts by it and
  persists — **no re-analysis**, so what was reviewed is what's saved (and no extra
  Claude call). `NutritionData::toArray()` / `fromArray($data, $scale)` round-trip
  through the session and apply the multiplier (confidence is not scaled).
- **Re-analyze with corrections** re-runs Claude with edited ingredients / answered
  questions, updating the session estimate; the user drives this (no auto-loop). The
  review can also **attach a second photo** (e.g. the nutrition-facts label the AI
  asked for): `FoodAnalysisService::analyzeImage` takes a **list of paths** and sends
  all images for the same meal in one request (with a note to combine them);
  `MealController::clarify` moves + HEIC-converts the uploaded `extra_photo`, appends
  it to the session's `image_paths`, and re-analyzes with every image. Extras persist
  across rounds (`extra_web_paths`, shown as thumbnails) and are **deleted from disk
  on save** — only the food photo is the meal's thumbnail. (Adding a real label
  typically lifts confidence sharply, e.g. ~0.71 → ~0.90.)
- **Primary button follows the state:** the review leads with **Save to log** when
  the estimate is confident (`ConfidenceLevel::isConfident`, ≥ 0.75) **or** the AI
  returned no more questions — once it stops asking, another re-analysis can't help,
  so Re-analyze is demoted to a secondary action (and the helper copy adapts). Only
  while questions remain *and* confidence is low does Re-analyze lead.
- **Round cap (2026-09-30):** refinement stops at `ClarifyRound::MAX_ROUNDS`
  = **3** (the first analysis is round 1), shared with the recipe import. Each round is a
  billable call the derived allowance can't see (the meal isn't saved yet), and the
  docs had long claimed this cap without it existing. At the cap the Re-analyze
  buttons, question inputs, and extra-photo card disappear, a `meal.clarify.round_limit`
  note says why, and a stale/crafted re-analyze POST just redirects back without a
  call. Covered by `tests/E2E/MealClarifyRoundCapTest`.
- **One clarify-round protocol for meals and recipes — `App\Service\ClarifyRound`
  (2026-10-06).** Both refine screens post the same fields (`answers[i]` for the AI's
  questions, `ing_name[]`/`ing_amount[]`/`ing_unit[]` for the ingredient table) and
  share the rules: `canContinue(round)` for the cap, `postedIngredients()` (blank names
  dropped; names ≤ 200 and amounts/units ≤ 50 characters, since they reach a billable
  prompt), and `nextContext()`, which appends this round's answered questions and the
  table as a **correction only when it differs** from the draft's list. Before, the
  recipe confirm posted `answer_i`, resent the unchanged table as a correction every
  round, and the meal review had no length limits. Covered by
  `tests/Service/ClarifyRoundTest` plus prompt checks in `MealLogPathsTest` and
  `RecipeImportTest` (the test fake records `lastAnalysisPrompt()`).
- **Saving keeps the ingredient table as the user left it (2026-10-06).** Before, an
  ingredient edit on the meal review counted only through a re-analysis: pressing Save
  stored the AI's original list, so correcting "fried chicken" to "grilled chicken" and
  saving changed nothing, including for the inflammation score, which matches on
  ingredient names. Save (and Save & favorite) now store the posted table, scaled by
  the portion multiplier as before. **Nutrition stays as estimated**, the recipe import's
  posture; changing the numbers is still a re-analysis. Pinned by
  `MealLogPathsTest::testSavingKeepsTheIngredientEditsWithoutAReanalysis`.
- **Portion math** (`PortionScaler` service) is shared by the photo-save and the
  favorite-log paths: `normalize()` coerces the posted multiplier (non-finite / ≤ 0 → 1,
  capped at `MAX_PORTIONS` = 100, the meal edit form's rescale cap — a posted `1e999` is
  `INF`, which a bare `> 0` check let into every stored nutrient); `scaleIngredients()`
  scales numeric amounts (non-numeric like "a pinch" untouched). Nutrition scaling
  itself stays in `NutritionData::fromArray($data, $scale)`.

## Meal history & editing (Phases 1–3 — view, favorite, edits, and AI re-analysis)
Implements the **whole** of `NouriLoop-Meal-History-Edit-Favorites-Plan.md` (business
folder; Phase 1 approved 2026-07-22 from a beta-tester request, Phase 2 built 2026-07-24,
Phase 3 built 2026-07-29). Phases 1–2 touch no downstream aggregate — every consumer
(dashboard, trends, inflammation, correlation, Predict, digests) reads meal rows live, so
every edit (including nutrient corrections and cross-day moves) is correct everywhere on
the next page load with no recompute step. **Phase 3 is the only part that costs an
Anthropic call**, and it is the reason Phase 2 records nutrition provenance.

- **History list** — `GET /meal/history` (`app_meal_history`, `MealController::history`):
  the user's meals newest-first via `MealEntryRepository::historyPage`/`countHistory`
  (shared private query builder so page + total can't disagree; nutrition fetch-joined —
  a to-one join, so `setMaxResults` paginates safely). Filters: `?from=`/`?to=`
  (inclusive `Y-m-d` bounds — `to` compares `< to+1 day`; values compare directly
  against the stored wall-clock `loggedAt`, no tz conversion) and `?type=`
  (`MealType`), all junk-tolerant (ignored, not errors) + `?page=` clamped, 25/page —
  the admin user list's GET-param idiom, incl. filters carried across page links
  (`filterParams|merge({page})`). Template `templates/meal/history.html.twig`
  distinguishes "no meals yet" (Log CTA) from "no match" (Clear filters).
- **Detail view / favorite-from-history**: `app_meal_show` and the "Save as favorite"
  form (`app_favorite_new`, builds the `Favorite` from the persisted meal's stored
  nutrition — zero Claude calls) **already existed**; Phase 1 added the history entry
  points + test coverage pinning the no-API-call property, and fixed
  `templates/meal/show.html.twig` so a **photo-free (described) meal still renders its
  AI description/ingredients/notes** (they were wrapped in `{% if entry.imagePath %}`).
  Unhiding the ingredient list exposed a latent strict-variables 500: stored ingredient
  rows aren't guaranteed an `amount`/`unit` key (the demo seeder omits `unit`), so the
  template now `|default('')`s them (found live-testing with the demo account; pinned
  by a unit-less ingredient row in `MealHistoryTest`'s seed helper).
- **The edit screen** — `GET|POST /meal/{id}/edit` (`app_meal_edit`, `MealEditFormType`,
  ownership-404 like show/delete): meal type, notes (same `NOTES_MAX_LENGTH` bound as
  logging), **date & time**, **manual nutrient corrections**, and a **one-shot portion
  rescale**. The Phase-1 time-only field became a full `DateTimeType` (single_text,
  string model `Y-m-d H:i:s` — the html5 widget reads/writes `Y-m-d\TH:i`, so seconds
  round to `:00` on save); the controller re-parses it in the user's zone (the
  `parseLoggedAt` idiom) and rejects a future instant (`meal.edit.future_time`, 422).
  - **Cross-day moves need a confirmation** (the plan's one deliberate speed bump — a
    move silently rewrites both days' totals/scores/charts): a posted date differing
    from the stored day without the `confirm_day_change` checkbox is a 422
    (`meal.edit.day_change_required`); the warning + checkbox block is revealed by
    inline JS as soon as the date input leaves the stored day (server-enforced
    regardless), and server-rendered open on the error re-render (`dayChangeNeeded`).
  - **Manual nutrient edits** cover `MealEditFormType::NUTRIENT_FIELDS` (calories,
    protein, carbohydrates, fat, fiber, sugar, sodium — headline macros + the three
    most consequential secondary values; micros stay AI-only, same posture as the
    favorite edit). Unmapped, prefilled; **blank keeps the stored value** (never nulls
    it), and only a genuinely different number counts as an edit, so re-saving the
    prefilled form is a no-op. Labels reuse `nutrient_full.*`. The nutrient + portion
    fields are **omitted entirely for a meal with no NutritionData row**
    (`include_nutrition` form option — the `include_sms` idiom; Copilot catch on
    PR #177: hidden-but-present fields would surface via `form_end()`'s leftover dump).
  - **Portion rescale** (`portions`, blank = none): multiplies the whole stored meal
    once on save — nutrition via the new **`NutritionData::scaleBy()`** (in-place
    sibling of `fromArray($data, $scale)`; confidence untouched) + numeric ingredient
    amounts via the existing `PortionScaler` (non-numeric amounts pass through).
  - **Edit provenance** (migration `Version20260724150000`, both nullable wall-clock
    datetimes on `meal_entry`): **`editedAt`** is stamped on any save that actually
    changed something — the plan §6 audit-trail marker, shown as a small "Edited
    {date}" line on the meal page header; **`nutritionEditedAt`** is stamped when a
    nutrient/portion edit changed the numbers — they're user-corrected, no longer the
    AI estimate, so the show page **replaces the AI-confidence bar** with an
    "Edited by you · {date}" marker, and Phase 3's re-analysis must diff-before-apply
    against any entry carrying it (the plan §4 rule). Columns live on `meal_entry`,
    so admin user-delete needs no change.
  - **Delete/allowance posture confirmed (plan §3's gaming question):** the Free
    monthly allowance stays **derived from meals logged this month**
    (`AnalysisAllowance` — no call-time ledger), so log-then-delete refunds the slot
    even though the Claude call happened. **Accepted deliberately** — identical to the
    recipe-import and analyzed-but-never-saved postures already documented under
    Recipe photo import; revisit with a usage ledger only if abuse appears. Pinned
    (as documentation, not as a guarantee) by a `MealEditPhase2Test` case. ⚠️ Phase 3's
    re-analyses are the **one** path that did get a call-time ledger — see below for why
    that isn't an inconsistency.
- **Phase 3 — AI re-analysis (`GET|POST /meal/{id}/reanalyze` → `…/reanalyze/review`)**:
  re-estimate an already-logged meal from a new/replacement photo, an edited
  description, or both. The plan's two cost-bearing edits, built as one flow because
  they differ only in what goes into the prompt.
  - **Two steps, always.** Step 1 (`MealReanalyzeFormType`, deliberately NOT bound to
    `MealEntry`) buys exactly one Claude call and writes **nothing** to the meal; the
    result is parked in the session (a `ReanalysisProposal`, key `meal_reanalysis`) and step 2 renders the diff.
    Only its Apply writes. ⚠️ The review guards the **meal id**, not merely the presence
    of state — a proposal parked for one meal must never be applied to another.
  - ⚠️ **The plan's §4 rule is enforced, not just displayed:** when the entry carries
    `nutritionEditedAt` (the user typed those numbers), Apply without the
    `confirm_overwrite` checkbox is a **422** — the same server-enforced speed bump as a
    cross-day move. The warning also appears on step 1, *before* the call is spent:
    there's no point charging someone for an estimate they'll then be told they can't
    apply.
  - **Provenance flips, in BOTH directions.** Applying stamps `MealEntry.reanalyzedAt`
    (migration `Version20260729215328`) and **clears `nutritionEditedAt`**: the numbers are
    a fresh AI estimate again, so the confidence bar is meaningful once more and renders
    with an "AI re-analyzed {date}" note. ⚠️ **The Phase-2 edit path had to be changed to
    match** — a manual nutrient/portion edit now also **clears `reanalyzedAt`**. The two
    stamps are mutually exclusive answers to "where did these numbers come from" and must
    never both be set; without that line a re-analysed-then-hand-corrected meal carried
    both, and the only reason it wasn't user-visible is that the show template's
    `nutritionEditedAt` branch happens to win. Found live-testing (2026-07-29), not by the
    suite — pinned now by the `testAManualNutrientEditAfterwardsClearsTheReanalysisStamp`
    case.
  - ⚠️ **Applying replaces the estimate wholesale** — all 26 nutrients (via the new
    `NutritionData::applyArray()`, the in-place sibling of `fromArray` that keeps the
    OneToOne row's identity instead of orphaning it, and which **nulls a field absent
    from the payload** rather than letting the previous run's figure mix with the new
    one), plus ingredients and `aiDescription`. The submitted description is stored as
    the meal's **notes**, so the next re-analysis starts from it — which also finally
    persists a photo-free meal's own wording, something the log flow only ever folded
    into the prompt and never saved.
  - **The diff is a real service, not template logic:** `App\Service\NutritionDiff` +
    `App\Dto\NutritionDiffRow`, pure and DB-free (the PortionScaler pattern) so the
    comparison the user is asked to approve is unit-testable without a controller or a
    Claude call. `NutritionDiff::ROWS` is the field→unit map (macros, then minerals, then
    vitamins) and covers **every** persisted nutrient on purpose: showing a subset while
    overwriting the rest would hide changes being approved. Rows where neither side has a
    value are dropped; unchanged rows are **kept**, so the table reads as a full statement
    of what applying would do rather than a highlight reel. `added`/`removed` are distinct
    from `up`/`down` (a nutrient the new run can't estimate must not read as a fall to
    zero), and a rise from 0 has a **null** percentage rather than "+100%".
    `hasChanges()` drives an explicit "the AI came back with the same numbers" notice —
    the call still cost a slot, so an empty-looking diff gets said out loud. *(Building
    this surfaced the missing `nutrient_full.phosphorus` key — now added in all six
    catalogs and pinned by a test asserting every compared field has a label, since the
    review table would otherwise print a raw translation key.)*
    ⚠️ **The table needs its mobile override** (a scoped `<style>` in the template, the
    `trends/index.html.twig` idiom): it has four columns and the LAST one carries the
    change, so at 375px the stock `.table-sm` padding pushed exactly that column past the
    `.table-responsive` edge — the page didn't scroll sideways, but the delta was the one
    thing scrolled out of sight. Below the `sm` breakpoint the cells tighten and the
    percentage stacks under the delta (`.diff-pct`). Found live-testing on mobile; if a
    fifth column is ever added, re-check this at 375px.
  - ⚠️ **A parked upload can be swept before it's applied, and applying blind LOSES the
    meal's real photo.** Those files are unreferenced by any `meal_entry.image_path` until
    apply — which is exactly what `app:cleanup-meal-uploads` deletes — and a session can
    outlive its 48 h threshold. `pruneMissingParkedPhotos()` therefore drops vanished paths
    (clearing `replace_photo` when nothing is left) on every review request, so apply can't
    repoint the meal at a missing file *and* `unlink()` its previous valid photo on the way.
    The estimate still applies (it was paid for); only the photo half is abandoned, with a
    warning flash and a `warning` log line. `applyReanalysis()` keeps a redundant `is_file`
    guard because it is the one place that deletes the existing photo. Copilot catch on
    PR #186, pinned by a test that unlinks the parked file mid-flow.
  - ⚠️ **The diff table is deliberately COLOUR-NEUTRAL.** A first draft painted every rise
    red and every fall green, which asserts a good/bad meaning nutrients don't have (more
    protein or fibre is usually wanted, more sodium usually isn't; "newly estimated" is
    neither) — on an approval screen that would read as dietary advice, which this app
    doesn't give. The ▲/▼ arrow carries direction and nothing else does. Copilot catch on
    PR #186; a test asserts the table's HTML contains no `text-danger`/`text-success`.
  - ⚠️ **Confidence renders on `is not null`, never truthiness.** `0.0` is valid under the
    analysis contract and "the AI has no confidence in this" is the reading most worth
    showing, but a truthy check hid the whole line. Same for the *previous* confidence in
    that sentence. (The diff rows were already explicit-null and were never affected.)
    `FakeAnthropicResponder::ZERO_CONFIDENCE` is the trigger marker that pins it.
  - **Photos:** uploads are capped at `MealEntryFormType::MAX_PHOTOS` and land in the
    meals dir with a `meal-reanalysis-` stem. ⚠️ **Leaving the upload empty on a meal that
    HAS a photo is not a description-only run** — the existing photo is still sent, so the
    help text is conditional (`photo_help_reuse` vs `photo_help`); the single string
    promised description-only and the controller never did that (Copilot catch on PR #186). A **`replace_photo`** tick (offered only
    when there IS a photo to replace — with none, an upload simply becomes the meal's, so
    a control would have one possible answer) makes the first upload the meal's own photo
    on apply and deletes the old file; otherwise every upload is an analysis aid and is
    deleted on apply — the log flow's `extra_web_paths` posture. The analysis sends new
    photos **first** (newest information), then the meal's own if it's being kept, sliced
    to `MAX_PHOTOS`; a photo dropped from the *analysis* is still on disk and can still
    become the thumbnail. Abandoned parked photos are swept by the existing
    `app:cleanup-meal-uploads` (unreferenced + old), and starting a second re-analysis
    drops the first one's uploads (`DraftSession::store()` does this for every draft).
  - **Cost + gating:** no new tier gate (the plan's §6 recommendation) — it draws on the
    shared monthly `AnalysisAllowance`, and the **`AnalysisEvent` call-time ledger**
    (`App\Entity\AnalysisEvent` + `App\Enum\AnalysisKind`) is what makes that true, since
    a re-analysis creates no countable row for a derived count to see. Booked only
    **after** the call succeeds (a failed analysis isn't charged — the retry posture) and
    for every tier (the row is also the cost record). Since 2026-10-06 the controller
    books nothing itself: it passes the meal to `FoodAnalysisService` as `reanalysisOf`,
    and `AnalysisAllowance::meter()` books `meal_reanalysis_photo` or
    `meal_reanalysis_description` against it. See Favorite meals ▸ Shared
    Free-tier bucket for the mixed-model warning and the deliberate
    no-refund-on-meal-delete asymmetry. ⚠️ `analysis_event` must be deleted **before**
    `meal_entry` in `AccountDeletionService::deleteUserRows` (and in `DemoDataSeeder`'s
    reset): its meal FK is `SET NULL`, so the rows would otherwise survive as user-scoped
    orphans and then block the `user` delete on their own non-nullable `user_id`.
  - **A meal with no `NutritionData` row gains one** from the re-analysis — the diff
    reads as all-additions, and it's the first estimate that meal has ever had.
  - The AI's questions are shown **read-only**: a single-shot re-analysis has no
    answer-and-refine round (that would be a second billable call), so they explain where
    the confidence sits rather than offering an action.
  - Entry points: a dedicated card on the meal page (its own card, not a peer of
    Edit/Delete, because it's the only meal action that costs a call) and a link from the
    edit page, whose `meal.edit.scope_note` flipped from "isn't supported yet" to a
    pointer at it.
  - Covered by `tests/Service/NutritionDiffTest` (the diff semantics above) and
    `tests/E2E/MealReanalyzeTest`: description round trip → propose → apply (incl. the
    dashboard live-read and notes persistence), manual corrections refused then
    confirmed + the provenance flip, discard leaves the meal alone but still counts,
    replace-vs-aid photo handling with on-disk assertions, the allowance spend +
    exhausted-redirect to /pricing, **delete does not refund a re-analysis slot**, a
    failed call charges nothing, nothing-to-analyze 422, cross-meal proposal refused,
    ownership 404s on both steps, the no-nutrition-row case, the provenance-stamp
    exclusivity round trip, the swept-parked-photo guard, zero confidence still rendering,
    and the colour-neutral change column.
- **Entry points:** navbar **More ▸ Meal History** (`chrome.nav.history`; the Log nav
  item's active-state excludes `app_meal_history` so two tabs don't light up), a
  "View meal history" link on the dashboard's Today's Meals heading **and** in its
  empty state (nothing today ≠ nothing ever), and history rows → detail view.
- **i18n:** `meal.history.*` / `meal.edit.*` / `meal.show.edit` /
  `dashboard.meals.history_link` / `chrome.nav.history` + compact macro abbreviations
  `macro.abbr_{protein,carbs,fat}` (per-locale letters: es P/C/G, de E/K/F, fr P/G/L,
  ru Б/У/Ж) shipped in en + all five full catalogs; the en strings deliberately avoid
  en_GB-divergent words (no "favorite"/"analyze"), so no override was needed. Phase 2
  reshaped `meal.edit.*` in en + all five full catalogs (dropped `time_label`/
  `time_help`; added `when_label`/`when_help`, the three `day_change_*` keys,
  `nutrition_heading`/`nutrition_hint`, `portion_label`/`portion_help`; re-texted
  `lead`/`scope_note`/`future_time`) and added `meal.show.edited`/`nutrition_edited`
  — again with no en_GB-divergent words ("analysis" is spelled the same; the verb
  forms were avoided).
  ⚠️ **Phase 3 broke that streak, unavoidably, and needed the first en_GB overrides
  in this area.** A feature named "Re-analyze" can't dodge the `-yze/-yse` split, so
  the whole `meal.reanalyze.*` block plus four `meal.show.reanalyze*` keys,
  `meal.edit.reanalyze_link` and `nutrient_full.phosphorus` went into en + all five
  full catalogs, and exactly the **seven** keys whose text contains a `-yze` verb form
  (`meal.reanalyze.title`/`heading`/`submit`, `meal.show.reanalyzed`/`reanalyze`/
  `reanalyze_cta`, `meal.edit.reanalyze_link`) got en_GB twins. Copy that says
  "analysis"/"re-analysis" (the noun) deliberately did **not**, since that spelling is
  shared — which is why the override list is seven keys and not forty.
- Covered by `tests/E2E/MealHistoryTest` (in-process `WebTestCase`: own-meals-only list
  + ordering + detail/edit ownership 404s, type/date filters + junk tolerance,
  pagination split + filters surviving the page boundary, favorite-from-history with
  a **zero-requests assertion on the mocked Anthropic client**, edit round-trip incl.
  date-unchanged + dashboard live-read, future-time 422 + unchanged row) and
  `tests/E2E/MealEditPhase2Test` (nutrient edit + provenance stamps + confidence-bar
  swap + dashboard live-read, blank-keeps-value / untouched-resave-stamps-nothing,
  portion rescale incl. amount-less ingredient pass-through + unscaled confidence,
  cross-day move refused without the confirmation then — confirmed — rebucketing
  the plan's two named boundary subsystems (`GoalMetricCalculator::dailyValues` and
  `InflammationScoreService::dailyScores`, the `CorrelationAnalyzer` score-by-date
  input, both keyed by local day), and the delete-refunds-allowance posture pin).

## Favorite meals (reuse without re-analysis)
Save a repeated meal with its full post-correction nutrition so it can be logged
again **without another Claude call** — the motivation is cutting token spend on
meals the user eats often. **Ungated — free on every tier** (the never-enforced
`favorites` entitlement key was retired 2026-07-22; see Subscriptions ▸ Tiers):
what Free pays for is the monthly `AnalysisAllowance` that *creates* favorites,
while reuse is deliberately free since it saves API spend.

- **Create** (`FavoriteManager::create`, copies the photo into its own upload dir)
  from two entry points:
  - the **Confirm & Refine** review screen — "Save to log & add to favorites"
    stores the **un-scaled, per-serving** estimate plus the **real analysis token
    cost** (`analysis_tokens` from the session), then logs the meal as usual;
  - the **saved-meal page** (`FavoriteController::new`, CSRF) — "Save as favorite"
    captures the meal exactly as stored; token cost is unknown there, so **0**.
  - Default name (`FavoriteManager::defaultName`) is the first clause of the AI
    description, else the first ingredient.
- **Model:** `Favorite` entity (per-user): name, thumbnail, `aiDescription`,
  `nutrients` (the `NutritionData::toArray()` shape — keyed map **including the
  un-scaled `confidenceScore`**), `ingredients`, `tokensPerAnalysis`, `useCount`,
  `createdAt`/`lastUsedAt`. `recordUse()` bumps the count + timestamp;
  `tokensSaved()` = `useCount × tokensPerAnalysis`.
- **Picker** (`/meal/favorites`, `FavoriteController::index`), reached from the
  Log a Meal page: searchable (JS name filter) + sortable
  (`recent`/`used`/`name`, `FavoriteRepository::SORTS`).
- **Log from favorite** (`/meal/favorite/{id}/log`, `FavoriteManager::logFromFavorite`)
  with a portion multiplier and meal type — **no Claude call**. Scales nutrition via
  `NutritionData::fromArray` (confidence inherited un-scaled) + ingredients via
  `PortionScaler`, copies a fresh thumbnail into the meals dir, links the favorite,
  and records the reuse. The new `MealEntry` holds its **own** copied nutrition.
- **Deleting a favorite keeps past meals:** `MealEntry.favorite` is a nullable FK
  with **`ON DELETE SET NULL`** (migration `Version20260611015440`); meals copied
  their nutrition + thumbnail at log time, so they only lose the back-reference.
- **Manage** on the favorites page: rename + headline-macro edit
  (`/edit` — micros/confidence untouched) and delete (`/delete`, unlinks the
  favorite's thumbnail) — both CSRF + ownership checked. Names are capped at 200
  characters (the column length) in `FavoriteManager::create` for every create path —
  until 2026-09-30 only the edit path capped server-side, so an overlong name from the
  meal page 500'd under strict mode.
- **Cost tracking:** `FoodAnalysisService` surfaces Claude's `usage`
  (`AnalysisResult::totalTokens()`). The user's favorites page shows only
  **analyses skipped** (= total reuses, `FavoriteManager::savings`); the
  **tokens-saved** figure is operator-only on the admin report
  (`/admin/reports/favorites`). Tokens count only where a per-analysis cost was
  captured — favorites made before tracking, or from a stored meal, contribute 0.
- Uploads live in `public/uploads/favorites/` (gitignored; needs the same
  `www-data` ACL as `public/uploads/meals/`).

### Recipe photo import (`/meal/recipe/scan` — a favorite from a recipe photo)
Photograph a recipe — a cookbook page, a printout, or a handwritten card (1–5
photos: front/back, several pages) — and ONE Claude vision call (OCR + whole-
batch nutrition together) turns it into a normal **Favorite** divided down to
one serving. No separate recipe entity for the user to learn; Phase 1 (MVP) of
`NouriLoop-Recipe-Photo-Import-Plan.md` (business folder), shipped 2026-07-22.
Nutrition source is Claude's estimate (the plan's recommended option 1) — no
food-database vendor was added.

- **`RecipeAnalysisService::analyzeImages(User, $paths, $context)` →
  `RecipeAnalysis` DTO** (title, servings-or-null, whole-batch `NutritionData`,
  confidence, questions, ingredients, transcribed `instructions`, tokens) —
  sibling of Food/SupplementAnalysisService: one `ClaudeClient::completeWithImages`
  call, so it shares their image encoding, oversized-image downscale (5 × 20 MB
  uploads must not blow the ~32 MB request cap) and fence-tolerant
  `ClaudeResult::json()` decode. `max_tokens` 4096
  (long ingredient lists + the steps transcription). The prompt asks for
  whole-batch totals, a numeric yield ("serves 4-6" → 5; best guess **plus a
  clarifying question** when unstated), vague quantities kept verbatim ("a
  pinch"), and a faithful transcription of the steps.
  `LocalePromptBuilder::recipeAnalysisJson` localizes title/questions, pins
  ingredient names English (inflammation keyword matching), and has the steps
  transcribed in whatever language the card itself is written in.
- **Shared Free-tier bucket — `App\Service\AnalysisAllowance`** (extracted from
  FoodAnalysisService; the CheckinAllowance derived-count pattern, no counter
  column **except for re-analyses** — see the mixed-model note at the end of this
  bullet): remaining = `FREE_MONTHLY_LIMIT` (10) − meals logged this month −
  recipe imports this month
  (`FavoriteRepository::countRecipeImportsForUserThisMonth` — favorites with a
  non-null `servingsAtImport`, created this user-local month; the user-local
  month boundaries are converted back to the **server** frame before comparing
  against the server-frame `createdAt` — Copilot catch on PR #171). Both
  analysis services gate through it, so an import spends one monthly analysis
  exactly like a meal photo. **Every analysis runs through
  `AnalysisAllowance::meter(user, ledgerAs, meal, analysis)`** (2026-10-06) — the only
  way in: refuse before any work when a Free user has none left, run the analysis,
  then book a ledger row with the result's tokens **only** when `ledgerAs` is given
  (the check and the booking are private). Which kinds are ledgered is
  `docs/adr/0001-mixed-allowance-ledger.md`, and
  `tests/Service/AnalysisAllowanceMeterTest` pins refuse-before-work,
  book-only-when-ledgered, failed-not-charged, and the kind each entry point books; the scan
  page shows the same allowance counter as the log page
  (`meal.log.allowance_left`). ⚠️ **Accepted tradeoff** (same posture as the
  meal count — an analyzed-but-never-saved meal isn't counted either — and
  CheckinAllowance's goal-delete reset): the marker is the saved favorite, so
  an abandoned confirm or a deleted favorite refunds the count even though the
  Claude call happened. Revisit with a call-time usage ledger only if abuse
  appears.
  ⚠️ **The bucket is now MIXED, and only one path is ledgered.** Meal
  **re-analysis** (Phase 3, 2026-07-29) overwrites an existing meal's numbers and
  creates nothing, so a derived count is structurally blind to it — a Free user
  could re-analyse without limit while their meal count sat still. Those calls
  therefore write an `AnalysisEvent` row (booked by `meter()` once the analysis
  succeeds — `FoodAnalysisService` books it when given `reanalysisOf`) and `remaining()` subtracts
  `AnalysisEventRepository::countForUserThisMonth` as a third term. Consequences,
  both deliberate: a re-analysis slot is **not** refunded by deleting the meal
  (the row survives via `ON DELETE SET NULL`), and re-analysis got the ledger the
  other paths were left without precisely because it is the unbounded-per-artefact
  one. Don't "unify" this by making meals ledgered too without deciding to change
  the documented log-then-delete posture above. **Supplement label scans** (2026-09-30)
  are the second ledgered kind (`supplement_scan`): a scan creates a row only if saved,
  so scan-and-abandon would otherwise be free.
- **Flow (`RecipeImportController`, `GET|POST /meal/recipe/scan` +
  `GET|POST /meal/recipe/confirm`, session-staged like the supplements scan):**
  upload (`RecipePhotoFormType`, ≤5 photos, HEIC-converted) → analyze →
  Confirm screen (editable title / servings / ingredient rows / instructions,
  batch macros + a live per-serving preview, the AI's questions with answer
  fields) → save divides the batch by the confirmed servings
  (`NutritionData::fromArray($batch, 1/$servings)`; numeric ingredient amounts
  via `PortionScaler`, "to taste" untouched) and calls the shared
  `FavoriteManager::create` (extended with optional `instructions` /
  `servingsAtImport` args — every other caller passes nothing). Server-side
  bounds mirror the HTML attrs (title 200 chars; servings must be **finite**
  and > 0 — `INF` from a crafted POST would otherwise reach the division and
  the DOUBLE column). **Re-analysis rounds** (`action=reanalyze` on the
  confirm POST, CSRF `recipe-confirm`, run by `ClarifyRound` like the meal flow's) send
  answered questions + the edited ingredient list back with all the photos;
  the user's typed title/servings/instructions win over the fresh AI values.
  A photo-limit hit at any point → `/pricing` with the
  `meal.log.limit_reached` flash. A malformed reply yields an **empty title**
  (no hardcoded-English fallback — the confirm screen's required field makes
  the user name it in their own language).
- **Files:** uploads are staged by `PhotoStaging` into the **meals** dir (`recipe-` filename stem,
  `bin2hex(random_bytes(16))` like every upload) so the daily
  `app:cleanup-meal-uploads` sweep covers abandoned imports for free; save
  copies the first photo into `uploads/favorites` via FavoriteManager and
  deletes all originals.
- **`Favorite` columns** (migration `Version20260722210000`, both nullable):
  `instructions` (display-only reference text, never re-parsed) and
  `servings_at_import` (the divisor, kept so a mis-typed count could be
  re-derived; non-null doubles as the recipe-import marker for the allowance
  count AND the Phase-2 badge). Both on the favorite row — admin user-delete
  needs no change.
- **Phase 2 (shipped same day, PR #172):** the favorites card shows a subtle
  **"From a recipe" badge** (derived from `servingsAtImport` — the plan's
  optional `source` enum stays unbuilt as unnecessary) and an
  **"Ingredients & steps" collapse** next to Edit: the cook's reference —
  the **whole-batch ingredient quantities** under an "Ingredients — makes N
  servings" heading, then the transcribed steps (auto-escaped user data,
  `white-space: pre-wrap`; there is no per-favorite detail page, so the card
  IS the detail view). Batch quantities come from
  **`Favorite.batch_ingredients`** (migration `Version20260722234500`,
  nullable JSON) — the confirm screen's list saved **verbatim, pre-division**
  (⚠️ the `ingredients` column is the per-serving division the meal pipeline
  logs from — useless for cooking, which is why this column exists); imports
  predating the column **reconstruct per-serving × servingsAtImport at
  display time** in `FavoriteController::index` (2-decimal rounding from the
  import division can drift slightly — e.g. ⅓ cup ÷ 8 × 8 → 0.32). Entry
  points: the favorites page header + its empty state (Phase 1) **and the
  dashboard header** — an outline "Import a recipe" button beside Log Meal.
  Keys `recipe.badge`/`recipe.steps`/`recipe.ingredients_for` (ICU plural)
  in en + all five full catalogs (no en_GB divergence).
- **i18n:** `recipe.*` keys shipped in en + all five full catalogs; en_GB
  overrides only the favourite-spelling strings. es_ES keeps "porciones"
  (checked against the existing catalog) — no Spanish-variant override. The
  form's max-photos constraint message ("…{{ limit }} photos of one recipe.")
  is in all five `validators.<locale>.yaml` catalogs, mirroring the meal
  form's line.
- **Public surface (2026-07-22, same-day follow-up):** the `import-recipe`
  **help topic** (auto-propagates per the Help center section: sitemap,
  JSON-LD `ItemList`/`TechArticle`, NL-assistant corpus incl.
  `help_assistant.keywords.import-recipe` in en + all five catalogs;
  `help_link('import-recipe')` on the scan page header) and **marketing
  mentions**: an `about.logging.items` bullet + about/pricing seo/jsonld
  descriptions, `pricing_page.feature.unlimited_photos` relabeled "Unlimited
  photo & recipe analyses", `pricing_page.free_note` now discloses BOTH Free
  allowances ({limit} check-ins + `{photoLimit}` monthly AI analyses, passed
  from `AnalysisAllowance::FREE_MONTHLY_LIMIT` via Twig `constant()`), and
  one recipe `<li>` each on `/lp/fiber` (photo_items), `/lp/weight-loss`
  (photo_items), and `/lp/diabetes` (one_place_items — condition-word rule
  respected in every locale). `/lp/inflammation` deliberately unchanged (its
  sections are the score mechanics + symptom correlation; no logging feature
  list to extend). All copy in en + the five full catalogs, with en_GB
  (favourite/fibre/personalised) and es_ES overrides where those files
  already override the touched keys.
- Covered by `tests/E2E/RecipeImportTest` (in-process `WebTestCase`: division
  math incl. the "to taste" pass-through, the zero-Claude-call
  `logFromFavorite` round trip, allowance gate firing before any API call AND
  an import consuming the shared bucket, a re-analysis round being exactly one
  more call incl. preserved manual instruction edits, non-finite/non-positive
  servings rejected, sessionless confirm redirect, and the Phase-2 badge /
  steps collapse / dashboard entry point),
  `tests/Service/RecipeAnalysisServiceTest` (parsing incl. null yield,
  prompt-capture: contract + locale rule + context shapes), and a
  `recipeAnalysisJson` case in `LocalePromptBuilderTest`.
  `FakeAnthropicResponder` matches the recipe prompt on its "ONE recipe"
  opener and returns an 8-serving batch with round numbers.

## Supplements (photo scan + adequacy)
Photo→Claude pipeline (sibling of the meal flow) for supplements the user takes.

- **Scan flow** (`SupplementsController`): `/supplements/scan` uploads **1–3**
  label photos (`SupplementPhotoFormType::MAX_PHOTOS`; reuses `HeicConverterService`) —
  multiple shots handle wrap-around labels. `SupplementAnalysisService::analyzeImages(User, …)`
  sends them all to Claude in one request (oversized images downscaled via
  `ApiImageEncoder`) (prompted to combine across photos) and reads **per-serving** nutrients in
  the app's units (handles `%DV`, IU→mass). The first photo is kept as the thumbnail,
  extras discarded (filenames use a random `bin2hex(random_bytes(16))` stem — see the
  unguessable-filename note under Meal logging). Then `/supplements/confirm`
  review/edit form → save. Missing photo files are dropped and a scan whose
  photos are all missing fails **before** any call, so nothing is charged (2026-10-06 —
  the shared `ClaudeClient` rule the meal and recipe analyzers already followed; before,
  the image encoder threw partway through). Parsing, the nutrient-key mapping and the
  ledger booking are unit-tested in `tests/Service/SupplementAnalysisServiceTest`.
- **Cost controls (2026-09-30 — scans had none):** a scan draws on the shared Free-tier
  **`AnalysisAllowance`** like meal photos and recipe imports (John's call: share the
  10, not a separate cap) — `AnalysisAllowance::meter()` refuses before the call (exhausted → `/pricing`
  with `meal.log.limit_reached`, uploads cleaned up, **no API call**), and the scan page
  shows the same `meal.log.allowance_left` counter. Because a scan only leaves a row if
  the user saves it, it's booked on the **call-time ledger** (`AnalysisEvent`, kind
  `supplement_scan`, with the response's token count) after the call succeeds — so an
  abandoned scan still counts, for every tier (the row is also the cost record). Covered
  by `tests/E2E/SupplementScanAllowanceTest`.
- **Model:** standing **daily** supplement, not per-day logging. `Supplement` entity
  (per-user): name, photo, **per-serving nutrients as JSON** (keyed by RDA key),
  `servingsPerDay`, `isActive`. `dailyNutrients()` = per-serving × servings/day.
  (`servingsPerDay`'s `#[ORM\Column]` default is the **string** `'1'`, not int `1`:
  MariaDB introspects a `DOUBLE` default back as `'1'`, so a string keeps
  `doctrine:schema:validate` from perpetually flagging the column — DB form unchanged.)
- **Adequacy integration:** `SupplementRecommendationService::getRecommendations()`
  reads the 7-day average **intake** (`DailyIntake::average()->totals()`: food plus each
  **active** supplement's daily contribution), so gaps/suggestions reflect what the
  user already supplements. Disable → drops out. ⚠️ **No meals in the window means no
  gaps at all, supplements included** (ADR 0002; until 2026-10-06 a supplement on its
  own produced gaps): `average()` is null, `computeGaps([])` is `[]`, and the page shows
  its no-data state. `computeGaps` also treats an all-zero average as "no data", which
  used to flag every nutrient for brand-new users (fixed 2026-07-22, new-user Wave 4;
  see Onboarding ▸ First-run polish).
- **Trends "Micronutrients" tile is driven by the same source.** The Insights/Trends
  overview tile (`InsightSummaryService::microsCard`) reads `getRecommendations()`'s
  supplement-inclusive gap list (passed in via `TrendsController`'s `microGaps` ctx,
  same window), **not** raw food averages — so it can't disagree with this page. The
  tile headline is the gap count, and it **links to `/supplements`** instead of
  opening an on-page chart; the old food-only "Daily Averages vs RDA" panel was
  removed. **Both pages offer the same `7/14/30/90`-day windows** (`SupplementsController`
  was widened to 90 to match Insights), and the tile links over the **exact** period
  selected — so the count and the page always agree, no clamping.
- **Manage** on `/supplements`: list, edit servings, enable/disable, delete
  (CSRF + ownership checked). Posted servings-per-day must be finite (a crafted `1e999`
  casts to `INF`, which `max(0.0, …)` passes; non-finite now stores 1) — the recipe
  import's `is_finite` guard, applied to confirm + update. Nutrient label/units come from
  `SupplementRecommendationService::nutrientMeta()` (the public view of the RDA map).
- Saved thumbnails live in `public/uploads/supplements/` (gitignored; needs the same
  `www-data` ACL as `public/uploads/meals/`). ⚠️ **A scan's photos are staged in
  `public/uploads/meals/` like every other draft's (2026-10-06)**, and the confirm save
  moves the kept thumbnail across with `PhotoStaging::keep()`. Before, they were written
  straight into `uploads/supplements/`, which `app:cleanup-meal-uploads` never sweeps, so
  every abandoned scan left its photo there for good. (Files orphaned before the change
  are still there; nothing removes them.) If the staged photo was swept while the
  confirm page sat open for 48 h+, the supplement saves without a thumbnail. Pinned by
  `tests/E2E/SupplementPhotoStagingTest`.

## Daily intake (`App\Intake\DailyIntake`, 2026-10-06)
Every screen that adds up what a user took in per day reads it from **one module**:
Trends, the Dashboard, the inflammation score, supplement recommendations and the
supplements page, Predict, goals (`GoalMetricCalculator`), the GLP-1 protein card,
and the evening calorie digest. A day exists only if a meal **with nutrition** was
logged on it (local `DATE(logged_at)`); the active supplements' daily amounts are
added on top. Three ways in: `days(user, from, to)` (one `IntakeDay` per meal day in
a half-open range, one SQL query for every nutrient via
`NutritionDataRepository::dailyNutrientTotals`), `forMeals(user, meals)` (the same from
meals already loaded, so the Dashboard's query budget stays flat) and
`average(user, from, to)` (an `IntakeAverage`: food averaged over the meal days, every
nutrient key present, and the supplements kept apart like an `IntakeDay`, with
`totals()` for the two together; null when there are no meal days). An `IntakeDay` keeps `food` and `supplements` apart, with
`total(nutrient)` for the sum, because the inflammation score labels supplement
factors and Predict subtracts the stack.
⚠️ **One rule, every screen** (`docs/adr/0002-supplements-count-on-meal-days.md`,
2026-10-06): supplements count on every day with a meal logged, and **never on their
own**. What that changed: goals and the GLP-1 protein card now include supplements
(they ignored them); the Dashboard no longer counts supplement calories on a day with
no meals; the Trends macros tile and pie, Predict's displayed baseline, weight and
narrative numbers, and the evening calorie digest now include supplements; and a
window with no meals shows no gaps even with an active supplement. "A day with meals"
means one with a meal that has nutrition (the Dashboard used to count any meal).
⚠️ **A supplement counts only from the local day it was added** (its `createdAt`,
2026-10-07; `Supplement::setCreatedAt()` exists only so the demo seeder can backdate
its stack to the start of the 60-day history): adding one today doesn't lift last
week's goal check-ins or redraw past Trends. An average counts it only on the meal days
since. Switching one **off** still removes it from past days, since supplements keep no
stop history. Covered by
`tests/Intake/DailyIntakeTest`, the supplement cases in `GoalMetricCalculatorTest` and
`Glp1ProteinServiceTest`, and `tests/E2E/DailyIntakeRuleTest`.

## Dietary inflammation score
A deterministic daily estimate of how pro-/anti-inflammatory the logged food was —
**an educational wellness signal, not a medical measure** (framed that way in the
UI). Motivated by diet↔symptom feedback (e.g. osteoarthritis flares).

- **`InflammationScoreService`** — pure heuristic, **no Claude call**, so it scores
  every already-logged meal retroactively. Two blended signals:
  - **nutrient backbone** (from `NutritionData::toArray()`): saturated fat / sugar /
    sodium push *pro*, fiber + antioxidant micros (A/C/D/E, B6, folate, B12, Mg, Zn,
    Se, normalized to %RDA, capped at 1 RDA) push *anti*. Directionality follows the
    Dietary Inflammatory Index (Shivappa et al.).
  - **food-category adjustments** — keyword match on `MealEntry.ingredients[].name`
    (e.g. processed meat / fried / sugary drinks → pro; fatty fish, olive oil, leafy
    greens, berries, turmeric → anti), capped per meal.
  - `scoreMeal()` returns a signed score + ranked factors (for tooltips);
    `dailyScores($user,$from,$to)` sums per **local day**, clamps to **−10…+10**
    (`MIN`/`MAX`), and tags a `band()` (strong-anti…strong-pro). Weights are
    documented, tunable constants; tests assert direction + clamping, not magnitudes.
- **Surfaced on** Trends (`TrendsController` → Chart.js line, band-colored points,
  factor tooltips) and the Dashboard (today's score badge). Meals pulled via
  `MealEntryRepository::findWithNutritionForRange` (fetch-join, no N+1); the
  Dashboard passes its already-loaded meals to `dailyScoresForMeals()` instead of
  re-querying them. **Dashboard query budget (2026-09-30):** today's meals are loaded
  once with nutrition fetch-joined and reused by calories, the inflammation score,
  glucose responses, and GLP-1 protein (a dead 23-column average
  call was dropped), so the page's query count is flat in the number of meals —
  pinned by `tests/E2E/DashboardQueryCountTest` (profiler query count, 1 meal vs 5).
  Every such range query is covered by `idx_meal_entry_user_logged` (`user_id,
  logged_at`, migration `Version20260930213006`); the FK's `user_id`-only index left
  the date filter to a row scan.
- **Page framing (`templates/trends/index.html.twig`):** this page is titled
  **"Insights & Trends"** (heading + nav) to signal it's an **over-a-period** view, not
  a today view (that's the Dashboard / "Today"). A subtitle states the active window
  ("Patterns across your last N days — not just today") and an inline legend explains
  the tile **arrows** = *direction over the period* (rising/falling/steady) while the
  tile **color** = good/watch/attention — they're independent (a falling arrow isn't
  inherently bad). The arrow direction itself is computed by `InsightSummaryService::trend()`
  (later-half vs earlier-half mean of the window).

### Symptom feedback loop (opt-in, multi-symptom)
Closes the diet→symptom loop: a daily self-reported check-in across one or more
**opt-in** symptoms, each correlated against the inflammation score. Generalized
from the original joint pain/stiffness-only model (migration `Version20260613173103`
replaced the old single `symptom_log` row/day; see git history).

- **Model — three tables (per-user):**
  - **`SymptomType`** — the user's symptom catalog: `typeKey` (slug), `label`,
    `isBuiltin`, **`isEnabled`** (the opt-in switch), **`higherIsBetter`** (positive
    symptoms like energy/acuity, where a higher 0–10 is *good* — flips the slider
    end-labels and the mood-emoji orientation, below), `position`. Unique
    `uq_symptom_type_user_key` `(user_id, type_key)`.
  - **`SymptomDay`** — one row/day holding the day's `note`, upsertable via
    **`uq_symptom_day`** `(user_id, logged_date)` (carried over from the old model).
  - **`SymptomEntry`** — one `level` (0–10) per symptom per day, unique
    `uq_symptom_entry` `(symptom_day_id, symptom_type_id)`. Both FKs are **`ON DELETE
    CASCADE`**.
- **Built-ins** live in the **`SymptomTypeKey`** enum (6: joint pain, joint
  stiffness, bloating/digestive, headache/migraine, **energy**, **mental acuity** —
  the last two `higherIsBetter`). **`SymptomTypeManager::ensureDefaultsFor($user)`**
  idempotently seeds them **disabled** (opt-in) for users created after the migration
  (called from `SymptomController::index`). The migration seeded existing users and
  **enabled joint pain/stiffness for prior loggers** to preserve their behavior.
- **`SymptomController`** (`/symptoms`): per-enabled-type sliders (prefilled from the
  day's entries) + a **Manage symptoms** panel (toggle on/off, add/delete custom —
  mirrors the Supplements self-management UI, CSRF + ownership). `/symptoms/save`
  upserts the `SymptomDay` + a `SymptomEntry` per posted `level_<typeId>` (CSRF
  `symptom-save`, `redirect=dashboard` for the quick card; the note is only touched
  when submitted, so the dashboard can't clear it). Manage routes:
  `app_symptom_type_create`/`_toggle`/`_delete` (custom only — built-ins disable, not
  delete). The **Dashboard** card renders only when **≥1 symptom is enabled**, so no
  user is forced to answer (a brand-new user sees no card).
- **Slider UX — mood emoji + middle default.** Each slider shows a **mood emoji**
  (not a colored number) to its right, driven by *goodness* rather than the raw value:
  `App\Content\SymptomMood::forLevel(level, higherBetter)` maps the level onto an
  11-step worst→best ramp (😩…😐…😁 — the "Gentle & warm" set), flipping for
  `higherIsBetter` so the face means the same thing for every symptom (joint pain 0 → 😁,
  energy 0 → 😩). This replaced the old chip whose **color was fixed by the symptom type**
  and so contradicted the value (green energy chip at 0, red joint-pain chip at 0).
  The ramp endpoints were softened (2026-06-27): the screaming 😱 worst and the
  hard-to-read star-struck 🤩 best were dropped for a gentle 😩 / clear grin 😁, since the
  user base skews older — and the face is sized up via a single **`.symptom-mood`** rule
  in `base.html.twig` (~2rem, replacing the old Bootstrap `fs-4`) for the same reason. The
  Dashboard card lays out label · slider · emoji in one row; the **slider + emoji are
  bound together in their own non-wrapping inner flex group** so the emoji can never wrap
  off onto its own line (only the label drops above on a very narrow screen) — otherwise
  the orphaned emoji landed at the far right below the grown slider. A
  **`.form-range[data-mood-slider] { display: block }`** rule kills the range input's
  inline-block baseline descender gap so the slider track centres on the row with the
  label and emoji (it sat a few px high otherwise).
  Rendered server-side via the **`symptom_emoji()`** Twig fn and kept live as the slider
  moves by a shared inline script (`templates/symptoms/_mood_script.html.twig`, seeded
  from **`symptom_mood_ramp()`** so JS and PHP can't drift); the numeric value stays in
  `title`/`aria-label`. Unset sliders **default to the middle (5 → neutral 😐)**, not 0
  — honest "nothing reported yet" without biasing the diet↔symptom correlation. Used by
  both the `/symptoms` page and the Dashboard card. Covered by `tests/Content/SymptomMoodTest`.
- **`DietSymptomCorrelationService::summarize($scoreByDate, $levelByDate)`** —
  symptom-agnostic, **reused unchanged**: avg level on pro-inflammatory (score ≥
  `PRO_THRESHOLD` = 1.0) vs other days, plus a Pearson `r`, **same-day and next-day**
  (inflammation can lag). Called **once per enabled symptom**; its pro-/other-day
  framing is kept verbatim under each symptom's diet chart on Trends.
- **Per-symptom relationship panels (Trends).** Each enabled+logged symptom gets its
  **own** insight tile (`symptom-<id>`) opening its **own** panel (no more shared panel
  + chip selector), with a **Diet** stack (symptom vs protein/carbs/fat/fiber +
  inflammation score) and a **Health** stack (vs steps/glucose/weight/sleep). Charts
  build lazily when the panel opens (`PANEL_INIT['symptom-'~id]`, registered by
  `setupSymptomPanels()`).
- **Symptom charts are small multiples, not one overlaid multi-line chart.**
  Six-plus normalized lines sharing one axis read as noise (real user complaint); each
  symptom panel is now a vertical stack of compact mini charts — one bar mini-panel for
  the symptom itself (0–10, own axis) and one line mini-panel per factor, **each in its
  own real units** (no more min–max normalizing everything onto a shared 0–1 "relative"
  axis). All panels share the same date columns so scanning straight down a stack shows
  whether a factor moved with the symptom on a given day. Built by
  `setupSymptomPanels()`'s `buildMultiples()` (replaces the old `buildChart()`); markup
  is a `.symptom-multiples` container (`templates/trends/index.html.twig`) that JS fills
  with `.symptom-mini-panel` rows, not a static `<canvas>`.
  - **Alignment gotcha:** Chart.js sizes each mini chart's y-axis label area from its
    own tick text, so e.g. `"20,000"` (steps) vs `"10"` (symptom) would otherwise start
    their plot areas at different x-offsets, breaking the "scan down a column" premise.
    Fixed with a fixed-width y-axis (`afterFit` forces `scale.width = MINI_Y_AXIS_WIDTH`
    on every mini chart) **plus** abbreviating large tick values (`formatMiniTick`, e.g.
    `12000` → `"12k"`) so the fixed width doesn't clip. The bottom-most panel in each
    stack shows the date axis; rotated/long tick labels there reserve extra overflow
    room that silently un-aligns just that one panel, so it uses horizontal, abbreviated
    date labels (`formatMiniDateTick`, e.g. `"Jun 1"`) instead of the app's usual rotated
    ISO-date `sharedXAxis`. Only the bottom panel of a stack ever shows x-axis ticks —
    every other panel uses a fully hidden x-axis and relies on the shared date columns.
- **Trends chart sizing.** The other detail-panel **time-series** charts (inflammation,
  health, calories, sleep) render inside a fixed-height wrapper
  (`.insight-chart-wrap` 340px, 260px on mobile) with **`maintainAspectRatio: false`**,
  so they get vertical room instead of being squashed into a ~100–150px band. The
  symptom small-multiples stacks size by content instead (each mini panel ~56–84px tall
  × however many factors exist). The **macro doughnut** self-sizes and is left alone.
- **`App\Service\CorrelationAnalyzer` + `App\Dto\CorrelationResult`** compute the
  significance shown **below each chart**. For every factor it runs a Pearson `r` at
  **same-day and next-day** lags and a **two-tailed Student-t test** (p from the
  regularized incomplete beta), surfacing only results with **n ≥ `MIN_N` (7)** matched
  days **and p < 0.05**, strongest |r| first. `TrendsController` runs it over the diet
  macros and the health metrics per symptom (`$symptomCorrelations`); inflammation is
  excluded here since it keeps the `DietSymptomCorrelationService` framing above. Copy
  is polarity-neutral ("rose and fell together with" / "moved opposite to"). Covered by
  `tests/Service/CorrelationAnalyzerTest` (known r/p, lag, clamp, significance gate) and
  `tests/E2E/SymptomCorrelationPanelTest` (planted correlation renders end-to-end).

## Postprandial glucose response & insights (CGM ↔ meals)
Turns blood-glucose readings (e.g. a Lingo/CGM sensor imported as `blood_glucose`
health metrics) into per-meal response curves, a measured-vs-expected check against
the meal's nutrition, and cross-meal rankings. **All deterministic — no Claude call,
no persistence; recomputed at view time**, so results improve as CGM data back-fills.
Educational wellness signals, **not** medical measures (framed that way in the UI).

- **Per-meal response** (`PostprandialResponseService` → `PostprandialResponse` DTO):
  pulls glucose in a window around the meal (`BASELINE_WINDOW_MIN` = 15 min before →
  `RESPONSE_WINDOW_MIN` = 180 min after) via
  `HealthMetricRepository::findGlucoseInWindow()` (lightweight DBAL, covered by
  `uq_health_metric`). Computes baseline (mean of pre-meal readings, else first
  at/after-meal reading), peak, time-to-peak, delta, **incremental AUC** (trapezoidal,
  above baseline) and return-to-baseline. Needs `MIN_READINGS_FOR_CURVE` = 6 post-meal
  readings for a curve (`hasCurve`); confidence high/moderate/low. Meal `loggedAt` and
  glucose `recorded_at` share the user's wall-clock frame, so the window is plain time
  arithmetic (no tz conversion). Surfaced on the **meal-detail page**
  (`MealController::show`, Chart.js curve + metric chips + narrative); degrades to a
  "limited data" note, hidden entirely when the user has no glucose at all.
- **Measured vs. expected** (`GlycemicImpactEstimator` → `GlycemicImpact` DTO): a proxy
  glycemic-load model over `NutritionData` — available carbs (carbs − fiber) × a
  sugar-weighted proxy GI (`GI_BASE` 55 → +`GI_SUGAR_PREMIUM` 30 at all-sugar), damped
  by fat and protein (rates/floors are tunable consts), → eGL → expected iAUC
  (`IAUC_PER_GL` 55) → ratio vs. the measured iAUC → 5 bands (much_lower…much_higher).
  Returns null for essentially zero-carb meals (< 1 g available). Shown as the "vs.
  Nutritional Estimate" block inside the meal-detail glucose card.
- **Cross-meal insights** (`GlucoseInsightsService` → `GlucoseInsights` /
  `AnalyzedMeal` DTOs) at **`/insights/glucose`** (`GlucoseInsightsController`, day
  window 7/14/30/90, **More ▸ Glucose Insights** nav). Reuses the two services above
  per meal (`findWithNutritionForRange`), so a row equals exactly what the meal page
  shows. Ranks **biggest rises**, **gentlest**, and **spiked-more-than-predicted**
  (ratio > 1, the Phase-2 moat ranked), each capped at `TOP_N` = 5, plus headline
  averages. `analyzeMeals()` is also reused by the **Dashboard** "Today's glucose
  responses" card (shown to anyone with glucose data via
  `HealthMetricRepository::userHasGlucose()`). Glucose is fetched **once per call**
  (`PostprandialResponseService::forMeals`, 2026-09-30 — it was one query per meal,
  ~360 on a 90-day window): one query spanning every meal's window, then each meal
  gets exactly the rows its own `forMeal()` query would (same inclusive bounds,
  compared as the same `Y-m-d H:i:s` strings), so results are identical — pinned by
  `PostprandialResponseServiceTest::testForMealsMatchesForMealWithOneQuery`.
- **Higher-cost AI tier (AI Pattern Coach):** the page's deterministic rankings are
  always on and free; the **AI Pattern Coach** deep-dive is a *separate*, token-spending
  tier. It is **dual-gated** — it runs only when BOTH the global
  **`GLUCOSE_AI_INSIGHTS_ENABLED`** env flag (`.env` default `0`, a master kill switch,
  bound to `GlucoseInsightsService::$aiDeepDiveEnabled` in `services.yaml`) **and** the
  **Loop+ `ai_pattern_coach` feature** are on. (The old third gate — the per-user
  `User.glucoseAiEnabled` admin toggle — was **retired 2026-07-24**, column dropped in
  `Version20260724220000`: services follow the tier, and admins adjust the tier via the
  Upgrade/Downgrade actions instead. Consequence: every Loop+ user, including the
  permanently-comped beta cohort and the demo account, gets the coach wherever the env
  flag is on — the per-user cache + ≤600-token cap bound the spend. ⚠️ Prod's
  `.env.local` must set `GLUCOSE_AI_INSIGHTS_ENABLED=1` for anyone to get it.)
  When both gates pass and the window has data,
  `GlucoseInsightsService::forUser()` calls `ClaudeClient` (model `claude-sonnet-4-6`,
  ≤600 tokens) to generate `GlucoseInsights::$coachingNarrative` from the user's own
  ranked meals — try/catch with a null fallback (logged), like `GoalEvaluationService`.
  The narrative is **cached** (`cache.app`, key = user + a hash of the prompt input, so
  it changes only when the meals/window do; ~1-day TTL; **failures aren't cached**), so
  refreshes, back/forward, and the day-window tabs don't re-bill tokens. `ClaudeClient`
  is injected **lazily** (`#[Autowire(lazy: true)]`) — since this service also backs the
  Dashboard, a non-AI page load (kill switch off / user not entitled) never instantiates
  the client or resolves `ANTHROPIC_API_KEY`, keeping the kill switch a true no-op.
  The template renders the narrative when present, the locked "Premium / Coming soon"
  upsell when the user isn't entitled, and a "Coach unavailable" notice when entitled
  but generation failed. Educational wellness signal, not medical advice.
- **CGM data caveat:** glucose must arrive at full resolution. Health Auto Export's
  **"Day" aggregation collapses glucose to one daily point** and breaks curves — use a
  per-reading export (the real Lingo feed samples ~every 5 min). Single-reading "days"
  in the data are typically `source='mock'` test rows, not a real ingestion problem.
  The **Connect your CGM wizard** (see its section) detects exactly this shape via its
  density check and tells the user to flip the aggregation setting.

## AI voice & tone (per-user)
Users tune how the AI **coaching narratives** sound on **Settings ▸ Voice & tone**
(`/settings/tone`, `SettingsController::tone`, custom slider form + CSRF `settings-tone`,
nav under the Settings dropdown). Three independent **1–5 dials** on `User`
(`tone_humor`, `tone_warmth`, `tone_education`, smallint default **3** = today's neutral
voice; setters clamp to 1–5; migration `Version20260626202813`). The template
(`templates/settings/tone.html.twig`) shows the humorous end-labels ("If I loosened my
tie, I might hyperventilate" … "Robin Williams on crack") with a live mid-point descriptor
as you slide.

- **`App\Service\TonePromptBuilder`** turns the dials into a compact instruction
  appended to the system prompt: `forUser(User)` / `build(h,w,e)` (level→phrase maps,
  clamped) and `signature(User)` for cache keys. It always ends with a **hard guardrail**
  — *style only; never change the facts, the plain-prose/no-Markdown rule, or the
  educational-not-medical-advice framing* — so the funny labels stay UI-only and the
  safety/format rules always win. Level 3 still emits a line (the neutral voice), one
  consistent mechanism.
- **Wired into the three coaching narratives only:** `GoalEvaluationService` (check-in),
  `GlucoseInsightsService` (Pattern Coach — the tone `signature()` is folded into the
  narrative **cache key** so changing your voice busts it), and `PredictiveInsightService`
  (both the computed and free-text narratives). The **functional** AI surfaces (help Q&A
  assistant, goal-setup chat) stay neutral for clarity. Available to all users (only
  affects narratives they can already access; no tier gate).
- Covered by `tests/Service/TonePromptBuilderTest` (mapping, defaults, guardrail, clamp),
  a tone-reaches-the-prompt assertion in `GlucoseInsightsServiceTest`, and
  `tests/E2E/ToneSettingsTest` (render + persist + clamp).

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
- **Claude calls** go through **`ClaudeClient`** (raw HTTP via Symfony HttpClient,
  **no SDK**; model `claude-sonnet-4-6`). Since 2026-10-06 it is the **only** module
  that speaks the Messages API: text conversations use `complete()`, and the meal,
  recipe and supplement analyses use `completeWithImages()` (images then prompt in
  one user turn, **no system prompt** — `FakeAnthropicResponder` routes on that
  shape); `ClaudeResult::json()` is the shared tolerant decoder.
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

- **Matrix:** Free = weekly check-ins, first **6 per account** (lifetime, not monthly),
  in-app only. Loop = unlimited weekly, in-app only, + **digest SMS**. Loop+ = weekly
  **or daily** (user's choice), + email/SMS delivery of check-ins. Digest email + in-app
  stay free on every tier (the evening email is the best Free retention tool; SMS is the
  only real per-message cost). Keys: `unlimited_checkins`/`sms_digest` (Loop+up),
  `daily_checkins`/`checkin_delivery` (Loop+) — resolved through `SubscriptionService`,
  so comps and grace periods apply.
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
- **Digest SMS gate:** `NotificationService` adds `sms_digest` **and a send-time
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

## Subscriptions / billing (Stripe — live)
Paid tiers via **Stripe** (web-direct Checkout; no App/Play-store IAP). The full flow is
built: hosted **Checkout** + **Billing Portal**, a signature-verified **webhook**, a public
**pricing page**, per-feature **gating** on existing services, and a **7-day trial** for new
subscribers. SDK: **`stripe/stripe-php`** (raw, no Symfony bundle), one shared
`Stripe\StripeClient` configured from `STRIPE_SECRET_KEY` in `services.yaml`.

> **Live in production (as of the v1.0.0 launch).** The Stripe dashboard is configured
> (products + prices + Billing Portal + webhook endpoint) and the **real `STRIPE_*` values
> are set in the server's `.env.local`** (secret key, webhook secret, the four price IDs).
> Paid upgrades work end-to-end; this is no longer a pending deploy item.

- **Env / config:** `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`,
  and the four price IDs (`STRIPE_LOOP_MONTHLY/YEARLY_PRICE_ID`,
  `STRIPE_LOOP_PLUS_MONTHLY/YEARLY_PRICE_ID`). Real (test/live) values live in the untracked
  `.env.local`; **`.env` carries non-secret placeholders for all of them** so the container
  compiles in CI/test (no `.env.local` there). All bound by argument name+type via a
  `_defaults` `bind:` block (`$stripeWebhookSecret`, `$loopMonthlyPriceId`, …).
- **Checkout / portal / sync** all live on **`SubscriptionService`** (the same entitlement
  service — its Stripe deps are **optional/nullable** constructor args so the pure
  entitlement unit tests still `new SubscriptionService($clock)`): `createCheckoutSession`
  (lazily creates+persists a `cus_…` customer, 7-day trial only when the user has never had a
  subscription), `createPortalSession`, `syncFromStripe(User, Stripe\Subscription)` (mirrors
  price→tier/status/periodEnd/subId/trialEnd onto the user, **does not flush** — caller owns
  it; unknown price logs a warning + leaves tier unchanged; `current_period_end` read with an
  items[] fallback for newer API versions), `syncFromCheckoutSession` (best-effort fast-path
  on the success page — ⚠️ the `?session_id=` is untrusted query-string input, so it syncs
  **only when the session's `customer` equals the user's `stripeCustomerId`**; otherwise
  it logs a warning and does nothing, since a mis-attached subscription would stick via
  `uq_user_stripe_subscription` and break the real owner's webhook sync; covered by
  `tests/Service/SubscriptionServiceCheckoutSyncTest`, which runs a real `StripeClient`
  against a canned `ApiRequestor::setHttpClient` fake), and `tierForPriceId`/`isKnownPriceId`.
- **`StripeWebhookController`** (`POST /webhook/stripe`, **PUBLIC_ACCESS**, not a Form so no
  CSRF): verifies `Stripe\Webhook::constructEvent` (→ **400** on bad signature) **before**
  touching data, looks the user up by `stripeCustomerId`, and handles
  `customer.subscription.created`/`updated` (sync), `.deleted` (status `canceled`, keep
  periodEnd for grace), `invoice.payment_succeeded` (re-sync), `invoice.payment_failed`
  (status `past_due`), `trial_will_end` (logs a placeholder). Each handler try/catches and
  still returns **200** so Stripe only retries on real infra failures.
- **`SubscriptionController`:** `/pricing` (PUBLIC_ACCESS, two-tier cards + monthly/annual JS
  toggle whose "save up to N%" badge is computed from the tier prices — the best saving across
  Loop/Loop+ — current-plan badge; above them a **Free strip** — `#free-plan-strip`,
  `pricing_page.free_strip`/`free_cta`, 2026-10-08 — stating Free's two allowances from the
  same constants as `free_note`, with a gold "Create a free account" button for visitors, the
  current-plan badge for Free users, and nothing for paying users; pinned by
  `tests/E2E/FirstVisitCopyTest`), `POST /subscription/checkout` (CSRF `subscription-checkout`,
  **validates `price_id` against the four known prices** before calling Stripe),
  `/subscription/success` (fast-path sync), `/subscription/cancel-checkout` (PUBLIC_ACCESS,
  bounce to pricing), `POST /subscription/portal` (CSRF `subscription-portal`), and
  `/settings/subscription` (per-user page: current plan + "Manage subscription" portal button
  for existing customers). Return/success URLs are built absolute via the router.
- **Feature gates wired in:**
  - **Free photo limit** — `FoodAnalysisService::analyzeImage(User, …)` throws
    `App\Exception\PhotoLimitExceededException` (caught in `MealController`, redirects to
    `/pricing` with an upgrade flash) once a Free user has used **10** analyses in their
    local calendar month (paid tiers have `unlimited_photos` → no count). The count is
    ONE shared bucket in `App\Service\AnalysisAllowance`: meals logged this month
    (`MealEntryRepository::countForUserThisMonth`) + recipe photo imports this month
    (`FavoriteRepository::countRecipeImportsForUserThisMonth`) + **meal re-analyses and
    supplement label scans this month** (`AnalysisEventRepository::countForUserThisMonth`
    — the call-time ledger; see Meal history & editing ▸ Phase 3 and Supplements ▸ Cost
    controls) — see Recipe photo import under Favorite meals.
  - **CGM correlation** (`cgm_correlation`, Loop+) — gated in `MealController::show` (locked
    upsell card when glucose data exists but the user isn't entitled) and
    `GlucoseInsightsController` (whole page → upgrade prompt).
  - **AI Pattern Coach** (`ai_pattern_coach`, Loop+) — **dual-gated** in
    `GlucoseInsightsService::forUser`: the global kill switch **AND**
    `hasFeature(user, 'ai_pattern_coach')`. (The per-user `glucoseAiEnabled` third
    gate was retired 2026-07-24 — see Glucose insights ▸ Higher-cost AI tier.)
- **`has_feature('…')` Twig function** (`App\Twig\SubscriptionExtension`) gates UI (e.g. the
  nav **Upgrade** link, shown only to Free users); false when nobody is logged in.
- **ToS:** a "Subscription and billing" section (tiers/pricing, auto-renewal, 7-day trial,
  refunds, cancellation, 30-day price-change notice) is in `templates/privacy/terms.html.twig`.
- Covered by `tests/Service/SubscriptionServiceStripeTest` (price→tier + sync mapping),
  `tests/Twig/SubscriptionExtensionTest`, and `tests/E2E/SubscriptionPagesTest` (public
  pricing renders, webhook rejects a forged signature). **No browser E2E for the paid flow** —
  exercise Checkout manually with the Stripe CLI (`stripe listen --forward-to
  localhost:8000/webhook/stripe`, `stripe trigger …`) + test card `4242…`.

- **Tiers** (`App\Enum\SubscriptionTier`): `Free` / `Loop` / `Loop+`. `hasFeature(string)`
  centralises gating — `unlimited_photos`, `unlimited_checkins`, `sms_digest`
  are **Loop and up** (i.e. any non-Free tier); `daily_checkins`, `checkin_delivery`,
  `cgm_correlation`, `ai_pattern_coach`, `predictive_help` are **Loop+ only**. Unknown
  keys return **false** (deny by default). ⚠️ The pre-tiering `goals` /
  `scheduled_checkins` keys were **retired** (2026-07-21, goal tiering): goal *definition*
  is free for everyone, and check-in gating moved to the unlimited/daily/delivery keys —
  see **Goal & notification tiering** under Nutrition goals. ⚠️ The `favorites` key was
  **retired the same way** (2026-07-22, Copilot catch on PR #173): it was never enforced
  anywhere — Free users have always created/logged favorites, and the recipe photo import
  depends on that — so **favorites are free on every tier** by design (reuse *saves*
  Claude spend; the paid lever is the `AnalysisAllowance` that creates them). The pricing
  Loop card's "Favorite Meals" row was removed and `pricing_page.free_note` now states
  favorites are included on every plan (en_GB overrides the favourite spelling; pinned by
  `GoalTieringTest`'s pricing case).
- **User columns** (migration `Version20260622172159`): `stripe_customer_id` +
  `stripe_subscription_id` (each **unique** — `uq_user_stripe_customer` /
  `uq_user_stripe_subscription` — so webhook sync can't mis-attach a subscription;
  nullable, many NULLs allowed), `subscription_tier` (enum, default `free`),
  `subscription_status` (mirrors Stripe: trialing/active/past_due/canceled/incomplete),
  `subscription_period_end`, `trial_ends_at`. (Admin user-delete needs no change — all
  live on the `user` row.)
- **`App\Service\SubscriptionService`** is the **single entitlement entry point** —
  always gate through it, never read `subscriptionTier` directly. `isSubscriptionActive`
  treats `active`/`trialing` as live and `canceled`/`past_due` as live **only within the
  grace period** (`subscription_period_end` in the future). `effectiveTier` **lazily
  downgrades** to `Free` once that lapses (no cron — checked at access time), and
  `hasFeature(User,$feature)` delegates to the effective tier. The entitlement half is
  SDK-free and unit-tested (`tests/Service/SubscriptionServiceTest`,
  `tests/Enum/SubscriptionTierTest`); the clock is injected (`Psr\Clock\ClockInterface`)
  for deterministic grace-period tests. (The Stripe-facing methods added on top are
  described under "Checkout / portal / sync" above.)
- **Complimentary grants (comp) — beta-tester grandfathering** (migration
  `Version20260622175104`): `User.comp_tier` (nullable enum) + `User.comp_expires_at`
  (nullable; **null = permanent**, a date = time-limited). `effectiveTier` checks an
  **active comp first** (`hasActiveComp`) and returns it before consulting Stripe — so a
  comp is billing-independent and a higher comp beats a lower paid sub; an expired comp
  falls through to the Stripe check (still lazy, no cron). Two cohorts:
  - **Existing beta testers** → **permanent Loop+** via the one-time command
    `php bin/console app:grandfather-beta-testers` (`--dry-run`; idempotent — skips users
    who already have a comp, so it won't clobber a beta-invite grant). Intended to be run
    once at the public launch to grandfather everyone who joined during the invite-only
    period (every pre-launch account was an invited beta tester). Post-launch, public
    sign-ups land on Free and don't get this comp — only a beta code does.
  - **New beta testers** → a **beta-tester invitation code** (see Invitation-code
    registration) grants **Loop+ for `BETA_COMP_DURATION` from registration** via
    `SubscriptionService::grantBetaTesterComp`, then lazily drops to Free.
    ⚠️ **Shortened from `+1 year` to `+45 days` on 2026-07-26, deliberately.** A comp that
    outlasts the beta makes a tester **structurally incapable** of converting to paid, which
    is exactly why the "zero paying users" figure carried no information: with ~12
    registrations and one unsolicited active user, the expiry of these comps is the only read
    available on whether anyone will pay (Marketing Plan §7.7). Tell testers the duration up
    front — the point is a signal, not a surprise. ⚠️ **`BETA_COMP_LABEL` is the
    operator-facing twin of the duration**, rendered by the mint checkbox, the
    outstanding-code badge and the mint flash. It exists because those three
    surfaces shipped saying "1 yr Loop+" while the grant was 45 days (Copilot
    catch on PR #184) — and an admin reading the wrong term to a tester defeats
    the entire mechanism, since telling them up front IS the design. Pinned in
    `BetaGrandfatheringTest` against the constant, not a literal. **The `app:grandfather-beta-testers`
    cohort is untouched**: they were promised permanent comps and still get them; only NEW
    code redemptions are time-limited. Pinned by `BetaGrandfatheringTest`, which asserts the
    granted expiry tracks the constant AND caps it at ~90 days — lengthen it back toward a
    year and the test says so. `grantComp($user,$tier,$expiresAt)` is the general form.
  - Covered by `tests/E2E/BetaGrandfatheringTest` (command + invite redemption + admin
    mint form) and comp cases in `tests/Service/SubscriptionServiceTest`.

## Testing & CI
- **Unit / functional** (`tests/`, `phpunit.dist.xml`): `vendor/bin/phpunit` — fast, no
  browser; **excludes `tests/E2E`**.
- **Browser E2E** (`tests/E2E/`, `phpunit.e2e.xml.dist`): Symfony **Panther** + headless
  Chrome — `vendor/bin/phpunit -c phpunit.e2e.xml.dist`. Covers login/auth, invite
  registration, dashboard, meal photo→clarify→save (+ portions), favorites, supplements,
  health-metric CSV import, trends, symptoms, goal setup (chat→confirm), settings,
  API-token ingestion, and admin access control. `tests/E2E/E2ETestCase` truncates + seeds
  a per-test user and clears cookies between tests (Panther reuses one browser).
  - The suite **also** holds a few **in-process `WebTestCase`** tests (not Panther —
    KernelBrowser/CommandTester) that need the **real DB** but no browser, kept here so
    they run in the DB-having e2e job and stay out of the DB-less unit job:
    `WelcomeEmailTest` (mail), `BetaGrandfatheringTest` (command + invite redemption +
    admin form), `AccountDeletionTest` (grace-period request/cancel + banner, "start
    fresh" data wipe, full account purge), `PurgeDeletedAccountsCommandTest`
    (dry-run/apply/cutoff), `AttributionTest` (UTM capture + the admin-only counts
    endpoint, incl. bearer-token calls), `SettingsHubTest` (hub renders every category,
    collapsed navbar link), and `AdminUsersListTest` (search narrows the list,
    pagination splits across pages, the plan/activity/status filters narrow correctly
    — incl. effective-tier where a comped-Loop+/stored-Free user files under Loop+ —
    filters survive a page boundary, and a dropdown row action still round-trips). They
    reuse `tests/E2E/DbResetTrait` for the schema reset.
- **Config tests** (`tests/Config/`): plain `TestCase`s that parse a YAML config file and
  assert an invariant no runtime test can reach, because the container under test is
  `test` and the config that matters is `prod`. Today that's
  `MonologHandlerPathsTest` — no two handlers in an env block may share a `path` (see
  **Logging (prod)**).
- **Dedicated test DB `nutrition_test`:** the `when@test` block in
  `config/packages/doctrine.yaml` appends `_test`, so `.env.test.local`'s `DATABASE_URL`
  keeps base name `nutrition`.
- **Claude is mocked** in `test`: `tests/Http/FakeAnthropicResponder` (a `MockHttpClient`
  response factory wired in `config/services_test.yaml`) replaces the HTTP client in
  `ClaudeClient` — the one module that calls the Messages API, so that single swap
  covers every Claude surface — deterministic,
  zero tokens, no network. It can also **simulate upstream failures**: putting its
  `FAIL_ALWAYS` / `FAIL_ONCE` const markers in the meal **notes** (which flow into the
  analysis prompt) makes the vision call 500 — every time, or only the first call after
  `resetFailureTrigger()` — used by `MealAnalysisRetryTest`.
- **chromedriver:** fetched by `vendor/bin/bdi detect drivers` into a gitignored
  `./drivers/` (version-matched to the installed Chrome; Panther auto-finds it there).
  Don't `brew install chromedriver` (it drifts from the Chrome version).
- **CI** (`.github/workflows/ci.yml`, on push/PR to `main`): a **`unit`** job (cs-check,
  `lint:container`, unit phpunit) + an **`e2e`** job (MariaDB 10.11 service, Chrome + bdi
  driver, migrate the test DB, run the Panther suite). Branch protection on `main` is
  **not** enabled — it's GitHub-Pro-gated on private repos.
- **Dependency security (Dependabot + scheduled audit, added 2026-07-03):** the repo's
  GitHub security **alerts** were already enabled; **`.github/dependabot.yml`** turns them
  into weekly update **PRs** (composer + github-actions ecosystems; routine minor/patch
  bumps grouped into one PR, security fixes always individual) that normal CI validates.
  A separate scheduled workflow (**`.github/workflows/security-audit.yml`**, Mondays +
  `workflow_dispatch`) runs **`composer audit --locked`** against `main` — deliberately
  **not** a PR gate, so a brand-new advisory in an unrelated package can't block a feature
  PR; on failure GitHub emails the workflow's last committer, and the fix is usually just
  merging Dependabot's PR. (Server-side OS/PHP patching is apt's territory — verifying
  `unattended-upgrades` on the VPS is a roadmap item, not repo process.)
  - **Version-update PRs target `dev` (2026-09-18):** both ecosystems carry
    `target-branch: dev`, so the weekly batch goes through the dev → main flow instead of
    around it. ⚠️ Dependabot reads its config from the DEFAULT branch, so this only takes
    effect once it reaches `main`; until then retarget a stray PR with
    `gh pr edit <n> --base dev`. **Security-update PRs always target `main` regardless.**
    CI's `pull_request` trigger was `[main]` only, so a PR into `dev` ran no checks at
    all — it is now `[main, dev]`, because green CI is the gate for the batch (step 3).
  - **Weekly triage process for the Dependabot batch** (~15 min, manual — branch
    protection is GitHub-Pro-gated on this repo, so there's no auto-merge-on-green):
    1. **Triage by ecosystem.** `github-actions` PRs are CI-only — no prod impact, no
       deploy. `composer` PRs change the app runtime and **don't reach prod until the
       next deploy**, so plan one after merging.
    2. **Security-labeled PRs first** (always individual, never grouped). Merge promptly
       once CI is green — these are what the Monday `composer audit` would start
       failing on.
    3. **Grouped minor/patch composer PR:** green CI (full unit + e2e suites) is the
       gate; skim the bump list for awareness and merge. No local testing for
       patch-level bumps.
    4. **Major bumps:** for *actions*, green CI is usually sufficient evidence because
       a PR's workflow run executes the PR's own workflow file — the bumped action
       already ran inside the passing check. **Exception:** steps gated on
       `if: failure()` (the `upload-artifact` Panther-screenshot step) are *not*
       exercised by a green run — skim those release notes instead of trusting the
       check. For *composer* majors, read the upgrade notes and run the e2e suite
       locally first; treat as a small project, not a rubber stamp.
    5. Merge in any order; if a PR falls behind after another merges, comment
       `@dependabot rebase` on it.
  - ⚠️ **An alert does not guarantee a PR — triage the alert list, not the PR list**
    (learned 2026-08-10, `squizlabs/php_codesniffer` CVE-2026-67434). Both repo toggles
    were on (`automated-security-fixes` `enabled`, `vulnerability-alerts` 204), the alert
    was raised 2026-08-08, and **no Dependabot PR ever appeared** — two days later the fix
    was still a manual `composer update squizlabs/php_codesniffer` on a hand-cut branch.
    Step 2 above ("merge the security PR") silently does nothing when there is no PR, so
    the alert would have sat until the Monday `composer audit` failed. The authoritative
    list is the alerts endpoint, not the PR page:
    ```
    gh api "/repos/john-c-houser-tech/nouriloop/dependabot/alerts?state=open"
    ```
    Suspected cause (unconfirmed): the manifest constraint already admitted the patched
    version (`^3.13.5` → 3.13.6), making it a **lock-only** fix. Treat any advisory whose
    fix is inside the existing constraint as one Dependabot may not raise for you.
- **Security policy (`SECURITY.md`, added 2026-08-10):** supported-version statement
  (hosted service — only the deployed release, never a backport), the `info@nouriloop.com`
  reporting path with timelines a solo developer can actually honor, scope in/out, and
  testing rules written around the fact that **real users' health data is in the system**
  (own-account-only, stop-on-incidental-access, minimum-proof).
  - ⚠️ **`SECURITY.md` isn't where the researchers it addresses will look.** This repo is
    private; since 2026-10-11 the file is also published in the public `nouriloop-app`
    mirror, but someone probing nouriloop.com has no reason to find either. The file
    documents the process; it is **not** the main way for an outsider to find the contact
    address.
- **RFC 9116 `security.txt` (`SecurityTxtController`, added 2026-08-10)** — served at
  `/.well-known/security.txt`, `PUBLIC_ACCESS` in `security.yaml`, covered by
  `tests/E2E/SecurityTxtTest`. ⚠️ **This route, not `SECURITY.md`, is NouriLoop's
  primary disclosure path** (see above for why). A controller rather than a static
  `public/` file for RobotsController's reasons: firewall-covered, testable, and
  `Canonical` stays correct across environments. It carries the testing rules **inline**
  in the comment block, because whoever reads it may read nothing else we've written —
  a contact address with no rules attached invites the "I dumped your user table to
  prove it" report.
  - ⚠️ **`Expires` is a fixed date, and `SecurityTxtTest::testExpiresIsNotAboutToLapse`
    fails 30 days before it.** RFC 9116 treats a lapsed file as stale, and an expired
    security contact reads as an abandoned project — worse than publishing none. A
    rolling `now + 1 year` would never expire, which defeats the field's purpose *and*
    means nothing ever prompts a review. So the staleness is made **loud**: CI goes red
    a month out, and the fix is to bump `EXPIRES` and re-confirm `info@` reaches a human.
    **That failing test is the entire annual review** — same principle as the daily
    digest sending on quiet days. Widening the window instead of bumping the date
    defeats the whole mechanism.
  - **`Policy:` links `SECURITY.md` in the public `nouriloop-app` mirror** (added
    2026-10-11; omitted before because a 404 inside a security.txt is worse than an absent
    optional field). The mirror trails the private repo between re-exports, which is why
    the inline rules stay. If the mirror ever moves, update `POLICY` (pinned by
    `SecurityTxtTest::testPolicyPointsAtThePublicSecurityMd`).

## Campaigner breadcrumb (`/about`)
The `/about` page's company note ("NouriLoop LLC is also the developer of Campaigner.") is
the public breadcrumb for Campaigner's Google Ads API review: their compliance team checks
the company website for content related to the application. It deliberately says only that
we're its developer, with no description of what Campaigner is for (John's call 2026-07-13).
Covered by
`tests/E2E/AboutPageTest`. The token-gated `/campaigner/{token}` description page that
accompanied it was **removed 2026-10-10** ahead of open-sourcing the repo (it carried the
Google Ads customer IDs and real ad-account screenshots).

## Ad landing pages (`/lp/{slug}`, public + noindex)
Audience-specific landing pages for the paid campaigns (Marketing Plan §8 in
the private business folder's `NouriLoop-Marketing-Plan.md`) — until these existed,
all four Google campaigns landed on the generic `/about`. Four pages, slugs matching the
campaigns' internal names: **`diabetes`** (Campaign A + Meta 1 — CGM correlation lead, the
Loop+ `$79/yr` vs `$200+/yr` comparison, "works with your Lingo or Stelo" CTA), **`inflammation`**
(Campaign D + Meta 2 — inflammation-score lead, symptom-correlation section, the true founder
story per §8), **`fiber`** (Campaign C — photo→micronutrients lead, 7-day gap analysis, "doctor
said eat more fiber" framing), and **`weight-loss`** (added 2026-07-19 for the Campaign B
rebuild — see `Campaigner/CAMPAIGN-B-REBUILD-PLAN.md`; honest-uncertainty lead per B's ad
Variant 1, dashboard/trends section, and a goal-check-in section whose copy **names Loop and
Loop+ with prices** — the Comprehensive Review §9 "don't imply gated features are free" rule,
pinned by `LandingPagesTest::testWeightLossPageLabelsPaidFeaturesHonestly`; reuses the
committed `lp/nutrition-summary.png` + `help/dashboard.png` + `help/goals.png` shots; the
`lp.weight_loss.*` keys shipped in en + all five full catalogs in the same change, avoiding
the word "favorites" so no en_GB override is needed).

- **`LandingPageController`** (`GET /lp/{slug}`, `app_landing_page`): `PAGES` const is the
  slug→seo_description registry (mirrors `HelpController::TOPICS`); unknown slug 404s.
  `^/lp` is `PUBLIC_ACCESS` in `security.yaml`.
- **Templates:** `templates/lp/_layout.html.twig` (brand mark deliberately **not a link** —
  no navigation, the register CTA is the only path forward; gold `btn-warning` CTA top and
  bottom via `lp_*` blocks; educational-wellness disclaimer above the base footer) + one
  template per slug. **`base.html.twig` has a `chrome_suppressed` flag** (keyed on the
  `app_landing_page` route, same idiom as `tracking_suppressed`) that hides every navigation
  surface on these pages — the signed-in navbar, the deletion/verification banners, the
  floating help button, the onboarding overlay, and the footer's "Send us feedback" link —
  so even a logged-in visitor arriving from an ad sees a CTA-only page. Unlike
  `tracking_suppressed`, trackers stay **on** (the ads need conversion measurement).
  Screenshots mostly reuse the committed `public/images/help/*.png`; prices come from
  `subscription_price()` (the `SubscriptionTier` single source), never hardcoded.
- **Dedicated screenshots in `public/images/lp/`** (committed, like the help shots):
  `meal-glucose-curve.png` (the per-meal Glucose Response card) and `glucose-insights.png`
  (the rankings page) for the `diabetes` page — added when the committed help `glucose.png`
  still showed the Free-tier locked upsell (wrong lead image for an ad; since 2026-07-22
  the demo user carries a permanent Loop+ comp and `glucose.png` shows the real page) —
  plus `nutrition-summary.png` (a meal's nutrient panel incl. fiber + micros) for the
  `fiber` lead. Regenerated by a one-off script (the Campaigner-screenshots pattern, not a
  committed command): same recipe as `app:capture-help-screenshots` (test env, seed demo,
  Panther — the comp is now baked into the seeder), then cropping to the relevant card.
- **Noindex, out of the sitemap, no robots.txt rule:** the layout's `meta` block appends
  `robots: noindex` (paid-traffic pages that duplicate `/about`'s story — kept out of the
  organic index so they can't compete with the canonical marketing page). Deliberately **not**
  Disallow'd in robots.txt — crawlers must be able to fetch them to see the noindex, and
  Google Ads' AdsBot needs them for Quality Score. GA4/Meta-Pixel behave as on any public
  page (they're anonymous marketing pages; §12 retargeting wants `/lp/*` visitors in the
  Pixel audience).
- ⚠️ **Platform messaging (2026-07-26, Marketing Plan §7.7).** The pages previously
  said neither "iPhone" nor "Android" — the only platform signal was the diabetes
  page's oblique *"from Apple Health"*, which a non-technical visitor won't read as
  "iPhone required". Since **everything except health-data import runs in any phone's
  browser**, three of the four pages were sitting under an implied iOS requirement they
  never had. Two things now:
  - **`lp.layout.any_phone`** renders under the hero CTA on **every** landing page (and
    `about.any_phone` on `/about`): works on the phone you already have, iPhone or
    Android, nothing to install.
  - **`lp.diabetes.sensor_note`** states the sensor × platform matrix *before* the
    visitor can click through — rendered in the layout's **`lp_precta`** block,
    which exists for exactly this. ⚠️ `lp_body` renders AFTER the hero CTA, so a
    caveat placed there is one a visitor can click straight past; that is where
    this first shipped (Copilot catch on PR #185) and it made the note
    worthless. `LandingPagesTest` now asserts the caveat's offset precedes the
    first `/register` link, and fails with that reason if it moves. ⚠️ **The load-bearing fact: on Android only Dexcom Stelo
    writes glucose to Health Connect first-party — Abbott Lingo does not share readings
    with other apps at all.** So the page's "works with your Lingo or Stelo" promise
    silently failed for Android+Lingo, on paid traffic. Losing those clicks is the
    point; they could never convert.
  - The CGM bullet and `/about`'s "Automatic syncing" bullet now say **automatic on
    iPhone, file import on Android today**. Android users *do* have a working path via
    the existing CSV/JSON importer — that is not a promise, it is current behaviour.
  - ⚠️ **Not claimed anywhere, deliberately:** automatic Android sync. `Health Connect
    Webhook` POSTs per-reading JSON with custom headers, which is exactly the shape
    `/api/health-metrics/health-export` wants (one new `HealthJsonImporter` away), but
    it is a small open-source project that needs a reliability trial before it goes in
    front of a 45–70 audience. Research lives in
    `NouriLoop Business/Android_support_chat.txt`.
- **Copy rules baked in (Marketing Plan §2/§9):** the `diabetes` page never says the
  condition word — "blood sugar"/"glucose" only (the slug is an internal campaign name;
  a test pins this); paid-tier features are framed honestly (Loop+ correlation + trial on
  `diabetes`, the 10-photo free cap + Loop price on `fiber`); the founder story is the only
  social proof (no invented user counts).
- Covered by `tests/E2E/LandingPagesTest` (in-process `WebTestCase`: all pages render
  anonymously with noindex and **`/register` as the only link target on the page**, a
  signed-in visitor gets no navbar/chrome and the same only-link guarantee, the diabetes
  page contains no "diabet…" string, unknown slug 404s, sitemap excludes `/lp/`).

## SEO & social meta (public pages)
First sprint of the SEO plan (`NouriLoop-SEO-Fundability-Plan.md`, "Engineering
Implementation Notes"). Goal: make the public marketing/help/legal surface
(`/about`, `/pricing`, `/help/*`, `/privacy/*`) discoverable; everything behind login
stays out of the index.

- **`RobotsController`** (`GET /robots.txt`, `text/plain`) and **`SitemapController`**
  (`GET /sitemap.xml`, `application/xml` + `templates/sitemap/index.xml.twig`) are
  **controllers, not static files** — so they pass the firewall, are testable in
  `WebTestCase`, and the robots `Sitemap:` line is generated from the real route. Both
  are `PUBLIC_ACCESS` via one rule `^/(robots\.txt|sitemap\.xml)$` (the catch-all `^/`
  needs `ROLE_USER`). **The dashboard is `/`, not `/dashboard`** — robots can't
  `Disallow: /` (blocks the site), so there's no dashboard rule; anonymous crawlers hit
  `/` → 302 to `/login`, so authenticated content is never indexable.
- **Sitemap URLs are derived from `HelpController::TOPICS`** (single source of truth) +
  a static-page list with per-route priority/changefreq. `<lastmod>` comes from the
  **`DEPLOY_DATE`** env var (`.env` placeholder empty → falls back to today in dev/test;
  set per-deploy in the server `.env.local`).
- **SEO/social meta** lives in a **`{% block meta %}`** in `base.html.twig` (right after
  the existing `{% block title %}`, which is **kept** — `og:title`/`twitter:title` reuse
  it via `{{ block('title') }}`, no duplicate `<title>`). It emits description + canonical
  + OpenGraph + Twitter-card tags. **Canonical is route-derived** (`url(_route, params)`)
  so it's clean (no query string) and host-correct, not `app.request.uri`. Public
  controllers pass **`seo_description`** (help pages reuse the topic `summary`; others a
  per-page blurb); everything else inherits the marketing default. **Authenticated pages
  get `<meta name="robots" content="noindex">`** (emitted when `app.user` is set) as a
  backstop to robots.txt — no private/health page is ever indexed.
- **OG card:** `public/images/og-card.png` (1200×630, brand teal→slate + the real
  `auth-logo.png` mark), referenced via `absolute_url(asset(...))`. Committed to git (like
  the help screenshots, **not** under gitignored `public/uploads/`). The **source is
  `public/images/og-card.svg`** (self-contained — the logo is embedded as a base64 data
  URI), so a tweak is a one-line regenerate:
  `rsvg-convert -w 1200 -h 630 public/images/og-card.svg -o public/images/og-card.png`
  (needs `librsvg`/`rsvg-convert` — `brew install librsvg`; ImageMagick's built-in SVG
  renderer drops `url()` gradients and can't resolve fonts, so use rsvg).
- Covered by `tests/E2E/SeoTest` (robots public + no `/dashboard` rule; sitemap is valid
  XML listing every help topic; `/about` carries title/description/canonical/OG; public
  page indexable, authenticated page noindex).
- **Google Search Console verification:** `public/googledc3af3f9ec545c4c.html` (a static
  53-byte token file). Served directly by the web server (nginx `try_files` / `symfony
  serve`), so it bypasses the firewall and returns 200 to Google's fetch — **don't delete
  it** or verification breaks. (HTML-file method; the DNS-TXT alternative in the SEO plan
  was not used.)

### Structured data / JSON-LD (Phase 2)
`App\Twig\StructuredDataExtension` builds JSON-LD, emitted inside `<script type="application/ld+json">`
via a **`{% block seo_jsonld %}`** in `base.html.twig` (empty default; public templates override it).
Three `jsonld_*` Twig functions, each returning a ready-to-print JSON string (slashes unescaped for
clean URLs, `<`/`>` hex-escaped so it can't break out of the `<script>`; all data is app constants/
routes, never user input):
- **`/about` → `WebApplication`** (`jsonld_web_application`): `HealthApplication`, `operatingSystem: Web`,
  and a per-tier **`offers`** array. Offer prices come from **`SubscriptionTier::monthlyPriceUsd()`**
  (the single price source — same one the pricing page now reads via the `subscription_price` Twig fn,
  so the schema can't drift from the page). Paid tiers use a `priceSpecification` →
  `UnitPriceSpecification` (`unitCode: MON`), the schema.org-correct recurring shape.
- **`/help` → `ItemList`** (`jsonld_help_list`): the 10 help topics from `HelpController::TOPICS`.
  **Chosen over the plan's `FAQPage`** — the topics are feature articles, not Q&A, so `FAQPage` would
  be inaccurate (and Google now restricts FAQ rich results anyway).
- **`/help/{topic}` → `TechArticle`** (`jsonld_help_article`, from the help `_layout`): headline +
  description from `TOPICS[slug]`, `image` = the topic's committed screenshot, publisher logo = og-card.
- Covered by `tests/E2E/SeoJsonLdTest` (valid JSON of the right `@type` on each page; `/about` offers
  match the `SubscriptionTier` price source; every topic emits a valid `TechArticle`) +
  `tests/Enum/SubscriptionTierTest` (the price methods).

### Analytics (GA4 + cookie consent)
Google Analytics 4 with **Consent Mode v2**, on the whole app but **off by a kill-switch**
unless configured.

- **`GOOGLE_ANALYTICS_ID`** env (`.env` placeholder empty → dev/test/CI emit **no**
  analytics script and **no** cookie banner; real `G-…` id only in the server `.env.local`).
  Exposed app-wide as the `google_analytics_id` Twig **global** (`config/packages/twig.yaml`).
- **`base.html.twig`** loads gtag.js + sets **Consent Mode v2 defaults to `denied`** (no
  analytics/ad cookies) until the visitor accepts; a returning visitor's choice (the
  `cookieConsent` first-party cookie, read server-side) seeds **all four** signals
  (`analytics_storage`, `ad_storage`, `ad_user_data`, `ad_personalization`) so they aren't
  re-prompted. **`templates/_cookie_consent.html.twig`** is the slim banner (vanilla JS —
  no Stimulus); Accept → `gtag('consent','update', {...all four signals: 'granted'})` + cookie;
  Decline → cookie only. Banner shows only when analytics is on **and** no choice is stored.
  ⚠️ **Fixed 2026-07-06** (caught via Google Ads flagging a 0% consent rate): the default
  block and the Accept handler previously only ever set/granted `analytics_storage` — the
  three `ad_*` signals were hardcoded `denied` with no code path that ever granted them, for
  *any* visitor including ones who clicked Accept. This silently blocked Google Ads
  click-to-conversion attribution account-wide (consistent with the near-zero conversions
  seen on the first live campaigns) without affecting GA4's own analytics reporting, which is
  why it went unnoticed at launch. All four signals now move together.
- **Health guardrail:** only `page_view` + **named conversion events** are sent, never any
  health value. Events: **`sign_up`** and **`purchase`** fire **exactly once** via a
  server-side one-shot **`track_event`** flash that base.html.twig emits on the next render
  (RegistrationController → auto-login redirect to the dashboard, since Wave 3;
  `SubscriptionController::success()` sets it
  then **Post/Redirect/Gets** to a clean URL so a refresh/bookmark of the `?session_id=` URL
  can't re-fire the conversion or re-run the Stripe sync). **`begin_checkout`** is the one
  client-side event (pricing form submit), `typeof gtag`-guarded so it no-ops when analytics
  is off. base.html.twig **always consumes** the `track_event` flash (only emitting it when
  analytics is on) so events never pile up in the session.
- ToS **§9 "Analytics and cookies"** (`#analytics`, linked from the banner) discloses it.
- Covered by `tests/E2E/AnalyticsTest` (kill-switch: empty id → no script/banner; ToS
  discloses analytics). The id-set path (snippet + banner + granted-cookie default) is
  verified manually — the compiled env var can't be flipped per-test.

### Meta Pixel (public pages only)
Same **`META_PIXEL_ID`**-driven kill-switch as GA4 (`.env` placeholder empty → suppressed
in dev/test/CI; real id only in the server `.env.local`; `meta_pixel_id` Twig global,
`config/packages/twig.yaml`) — but with a **stricter scope than GA4**, decided deliberately:
Meta Pixel builds ad-targeting audiences from page visits, not just analytics, and there's
real FTC/litigation precedent (BetterHelp, GoodRx, several hospital systems) for exactly
this pattern — a pixel firing on health-adjacent pages. So unlike GA4 (which loads on every
page including `/health-metrics`/`/symptoms`/`/trends`/`/insights/*`), the Pixel in
`base.html.twig` only loads when **`app.user is null` OR there's a pending `track_event`
flash** — i.e. public/marketing pages (`/about`, `/pricing`, `/help/*`, `/login`,
`/register`, `/privacy/*`) plus the one-shot conversion redirects. It **never** loads on any
authenticated page during normal browsing — dashboard, meals, symptoms, health metrics,
trends, insights, settings are all excluded even when the id is configured.
- **Conversions** reuse the same **`track_event`** flash as GA4 (`RegistrationController`
  → `sign_up`, `SubscriptionController::success` → `purchase`) — no new server-side
  mechanism. `base.html.twig` maps flash values to Meta **standard events** via a small
  inline dict (`sign_up`→`CompleteRegistration`, `purchase`→`Purchase`), fired bare with no
  value/currency params (same no-PII posture as the GA4 events). The pending flash is
  what forces the Pixel to load on an otherwise-excluded, authenticated page — the
  `track_events is not empty` clause covers both such cases: `purchase` on
  `/subscription/success`, and (since Wave 3's auto-login) `sign_up` on the dashboard
  render right after registration. Each is a genuine one-shot (flash consumed on that
  single render).
  On that authenticated page **only the conversion event fires — the `PageView` (both the
  JS `fbq('track','PageView')` and the `<noscript>` beacon) is gated on `app.user is null`**,
  so a page view is never sent on a logged-in page, matching the ToS ("only page views on
  public pages"). Public pages (where `app.user is null`) send both the PageView and any
  conversion.
- **Consent** uses Meta's own `fbq('consent', 'grant'|'revoke')` API, same denied-by-default
  posture as GA4's Consent Mode: `revoke` is sent **before** `init` (Meta's documented order,
  so nothing acts pre-consent), then `grant` only if the shared `cookieConsent` cookie already
  reads `granted`. The `<noscript>` pixel fallback can't consult the JS consent API, so it's
  **gated server-side on `cookieConsent == 'granted'`** — a no-JS visitor can't have opted in
  (the banner needs JS), so it stays off by default. The **cookie banner is shared** with GA4
  (`_cookie_consent.html.twig` — triggers on `google_analytics_id OR meta_pixel_id`, Accept
  grants both, Decline denies both).
- ToS **§9 "Analytics and cookies"** has a dedicated Meta Pixel paragraph disclosing the
  public-pages-only scope. Covered by `tests/E2E/AnalyticsTest`
  (`testMetaPixelSuppressedWhenIdIsEmpty` — kill-switch; ToS disclosure assertions). The
  id-set + scope-restriction path is verified manually, same reason as GA4.
- **Deliberately not implemented:** `begin_checkout`/`InitiateCheckout` parity — GA4 fires
  `begin_checkout` client-side on the pricing page's checkout form submit, but the pricing
  page is reachable while logged in (nav "Upgrade" link) and the Pixel's exclusion rule is
  `app.user is null`, so mirroring it would either violate the never-on-authenticated-pages
  rule or need a separate carve-out. Skipped for now; revisit only with an explicit decision
  to loosen the scope.

## Secrets / env
- `.env` holds non-secret defaults; real secrets live in the **untracked**
  `.env.local` (`APP_SECRET`, `DATABASE_URL`, `ANTHROPIC_API_KEY`, `TWILIO_DSN`).
  Never commit secrets to `.env`/`.env.dev`. `.env.test` carries a throwaway test
  secret.

## Deployment (production)
Production runs on a single Ubuntu VPS (Nginx + PHP-FPM + MariaDB, cron for the scheduled
commands, Postfix relaying mail). The host layout, the `scripts/deploy.sh` procedure
("Deploying an update"), prod logging, SSH hardening, backups and the hard-won gotchas live
in **`docs/ops/production.md`**, which is kept out of the public export.

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Uses the five default triage labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` and `docs/adr/` at the repo root, created only when first needed. See `docs/agents/domain.md`.
