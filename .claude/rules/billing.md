---
description: "Stripe Checkout, the portal and webhook, feature gates, tiers, SubscriptionService as the single entitlement entry point, and complimentary grants."
paths:
  - "src/Service/SubscriptionService.php"
  - "src/Hosting/**"
  - "src/Enum/SubscriptionTier.php"
  - "src/Controller/SubscriptionController.php"
  - "src/Controller/StripeWebhookController.php"
  - "src/Twig/SubscriptionExtension.php"
  - "src/Command/GrandfatherBetaTestersCommand.php"
  - "src/Exception/PhotoLimitExceededException.php"
  - "src/Service/AnalysisAllowance.php"
  - "src/Service/AllowanceTopUpService.php"
  - "src/Controller/AllowanceController.php"
  - "src/Entity/AllowanceTopUp.php"
  - "src/Repository/AllowanceTopUpRepository.php"
  - "templates/allowance/**"
  - "src/Enum/AllowanceKind.php"
  - "src/Dto/AllowanceStatus.php"
  - "templates/_allowance_counter.html.twig"
  - "templates/subscription/**"
  - "templates/privacy/terms.html.twig"
---

# Subscriptions and billing — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

## Subscriptions / billing (Stripe — live)

> **The tier rework (App Development Plan PR 7, 2026-10-12; ADR 0003, decisions Q12a–e).**
> Three plans, **monthly only** (the yearly prices and the pricing page's annual toggle
> were retired); the names stay, each with a descriptor line on the pricing page:
> - **Free** — unchanged: `AnalysisAllowance::FREE_MONTHLY_LIMIT` (10) analyses a month,
>   `CheckinAllowance::FREE_ALLOWANCE` (6) lifetime check-ins, paid features locked.
> - **Loop — "Bring your own AI key", $2/month.** Every feature except SMS. Its AI calls run
>   on the key the user saves in Settings ▸ AI provider (`ai-client.md`); until a key is
>   saved it gets Free's 10 analyses a month (`AllowanceKind::KeyPending`).
> - **Loop+ — "AI included", $13.50/month** (priced at cost from John's own usage, no
>   margin). Every feature, SMS included, and `AnalysisAllowance::INCLUDED_MONTHLY_LIMIT`
>   (120) analyses a month on our key (`AllowanceKind::Included`); a saved key of their own
>   lifts the cap. When it runs out, analyses pause until the month turns or the user buys a
>   top-up (below).
> - **Feature keys** (`SubscriptionTier::hasFeature`): `paid_plan` (any paid tier — the
>   navbar Upgrade nudge), `sms` (**Loop+ only** — Twilio's fees are a cost a user's AI key
>   doesn't cover; gates both the digest and check-in texts in `Reachability`),
>   and `unlimited_checkins`, `daily_checkins`, `checkin_delivery`, `cgm_correlation`,
>   `ai_pattern_coach`, `predictive_help` on **both paid tiers**. `unlimited_photos` and
>   `sms_digest` were retired: analyses are `AnalysisAllowance`'s business, not a key.
> - **`AnalysisAllowance::kind()/limit()/status()`** decide who is counted: none on a
>   self-hosted copy, none for a paid user with their own key, Free 10 (a Free user's own
>   key does **not** lift it — the allowance is what Free is), Loop 10 until a key is
>   saved, Loop+ 120. `PhotoLimitExceededException` carries the `AllowanceKind`, whose
>   `limitReachedKey()`/`route()` every catch site uses: Free → `meal.log.limit_reached` +
>   `/pricing`; Loop → `limit_reached_key_pending` + `/settings/ai`; Loop+ →
>   `limit_reached_included` + `/settings/ai`. The counters on the four analysis pages are
>   one partial, `templates/_allowance_counter.html.twig`, fed `allowance` =
>   `AnalysisAllowance::status()` (`App\Dto\AllowanceStatus`).
> - **Prices** are `SubscriptionTier::monthlyPriceCents()` (0 / 200 / 1350);
>   `monthlyPriceUsd()` is the machine decimal string ("13.50") for JSON-LD, and the
>   `subscription_price(tier)` Twig function formats it for the request locale ("13,50" in
>   German) — a string, because ICU would print a float as 13.5.
> - **Stripe**: two price IDs, `STRIPE_LOOP_MONTHLY_PRICE_ID` ($2) and
>   `STRIPE_LOOP_PLUS_MONTHLY_PRICE_ID` ($13.50). ⚠️ They must point at **new** Stripe Prices
>   (create test, then live); a subscription still on a retired price syncs as an unknown
>   price (logged, tier unchanged) — PR 8 retires the old prices and moves John's account.
> - The 7-day trial is unchanged. The ToS billing section (§16) states the new plans.
>
> **The Loop+ allowance top-up (PR 7b, 2026-10-12; decision Q3).** A one-off Stripe
> Checkout (`mode: payment`, price `STRIPE_TOP_UP_PRICE_ID`) adds
> `AnalysisAllowance::TOP_UP_ANALYSES` (60) analyses for `TOP_UP_PRICE_CENTS` ($1.75) to the
> user's **current** month only; unused ones lapse. Only Loop+ on our key can buy one
> (`AnalysisAllowance::canTopUp()`).
> - **Only analyses pause** at the limit (John, 2026-10-12, option a): the help assistant,
>   goal chat, check-ins, Pattern Coach and predictions keep running — they were part of the
>   $13.50 cost estimate and their spend is visible in `app:ai-costs`. The counter turns
>   amber with its link at **80% used** (`AllowanceStatus::isLow()`, every plan).
> - **`/allowance`** (`AllowanceController`, hosted-only, `ROLE_USER`): what's left, the
>   top-up button (`POST /allowance/top-up`, CSRF `allowance-top-up`) and the own-key route.
>   `AllowanceKind::Included` sends a used-up Loop+ user here; anyone else is redirected to
>   their own `route()`. Settings ▸ Subscription links to it for Loop+.
> - **Crediting** (`AllowanceTopUpService::credit()` → `App\Enum\TopUpCredit`
>   Credited / AlreadyCredited / NotCredited; the success page says "added" for either of the
>   first two, since the webhook usually wins the race): one `allowance_top_up` row per Checkout
>   session (unique `stripe_session_id`), so the webhook (`checkout.session.completed` /
>   `checkout.session.async_payment_succeeded`, authoritative) and the success page's fast
>   path (`/allowance/top-up/success`, which requires the session's customer to be the
>   signed-in user) can both arrive and only one writes. It ignores anything that isn't a
>   paid session with `metadata.purpose = allowance_top_up`. The month is fixed in the
>   session metadata when checkout starts (user-local `Y-m`), so a payment that lands after
>   midnight on the 1st tops up the month the user was looking at.
> - ⚠️ **A failed top-up credit returns 500 from the webhook** (unlike every subscription
>   event, which is logged and acknowledged): the fast path is best-effort, so the webhook
>   is the only sure way a paid top-up reaches the user, and Stripe only retries on a
>   non-2xx. `credit()` is idempotent, so retries are safe. Copilot catch on #248.
> - ⚠️ **Stripe setup before deploy:** create the one-off $1.75 Price (test, then live) for
>   `STRIPE_TOP_UP_PRICE_ID`, and add `checkout.session.completed` and
>   `checkout.session.async_payment_succeeded` to the webhook endpoint's events.
> - **Account deletion:** `purgeUser` deletes the rows (they're paid records, so "start
>   fresh" keeps them, like the subscription).
> Covered by `tests/Service/AnalysisAllowanceLimitTest` (limit with a top-up, who can buy,
> the 80% line) and `tests/E2E/AllowanceTopUpTest` (page access per plan; a Stripe-signed
> webhook credits once for the right month; unpaid, non-top-up and foreign-customer
> sessions are ignored; purge removes them) plus `SupplementScanAllowanceTest`.
> Covered by `tests/Enum/SubscriptionTierTest`, `tests/Service/AnalysisAllowanceLimitTest`
> (the plan × key matrix and each used-up destination), `tests/Outbound/ReachabilityTest`,
> and the E2E `FirstVisitCopyTest`, `GoalTieringTest`, `MealLogPathsTest`,
> `SupplementScanAllowanceTest`.

> **One subscription per customer (App Development Plan PR 8 test-mode run, 2026-10-13).**
> A subscriber who chose the other plan on `/pricing` (Settings ▸ Subscription's "Change
> plan" links there) used to get a second Checkout, so Stripe held two live subscriptions on
> one customer, billed both, and the webhook overwrote `stripe_subscription_id` with the
> newer one. Now `SubscriptionService::hasLiveSubscription()` (a stored subscription id with
> a status in `PORTAL_STATUSES`: active, trialing, past_due, unpaid, incomplete; a comp alone
> doesn't count) decides: such a user's plan buttons read `pricing_page.switch_plan`, and
> `POST /subscription/checkout` sends them to the Billing Portal's change-plan screen for
> their own subscription (`createPortalSession(..., switchPlan: true)`, `flow_data` type
> `subscription_update`, back to Settings ▸ Subscription) instead of Checkout. The check is
> in the controller, so no template can get around it. An `incomplete` subscription (first
> payment still payable for about a day) gets the portal home instead, since the portal can't
> change its plan (`SWITCHABLE_STATUSES`). For a subscriber the pricing cards follow
> `billedTier()` (the plan Stripe bills), not `effectiveTier()`, so a comp above the paid
> plan doesn't hide the upgrade (both Copilot catches on #249).
> - ⚠️ **The portal's default configuration is NouriLoop's** (Auditor always passes its own
>   `bpc_…`): its plan switch must list exactly the Loop and Loop+ monthly Prices, with
>   proration on and cancellation at period end. A plan switch during the trial ends the
>   trial and bills the new plan at once (Stripe's portal behavior; raised with John 2026-10-13,
>   not yet decided).
> Covered by `tests/Service/SubscriptionServiceTest` (the status matrix),
> `tests/Service/SubscriptionServicePortalTest` (the portal session params) and
> `tests/E2E/PlanSwitchTest` (a trialing subscriber lands in the portal, a canceled one
> checks out again, a comped subscriber's cards follow the billed plan).

> **A canceled plan shows when it ends (PR 8, 2026-10-13).** After canceling in the portal,
> Settings ▸ Subscription used to read just "Loop+", with nothing to say the cancellation took.
> `syncFromStripe()` now mirrors a scheduled cancellation into `User.subscriptionCancelAt`
> (migration `Version20261014013701`): Stripe's `cancel_at` on recent API versions, the period
> end when only `cancel_at_period_end` is set, and null when neither (so resuming in the portal
> clears it). `SubscriptionService::scheduledEnd()` gives the page its date: the scheduled
> cancellation for a live subscription, the paid period's end for a `canceled` one still in
> grace, nothing once that date has passed. The page renders
> `settings.subscription.ends_on` ("Canceled. You keep {plan} until {date}.", en_GB
> "Cancelled") with the date through `user_date`.
> - **Events for a replaced subscription are ignored.** `customer.subscription.deleted` for a
>   subscription id other than the stored one is logged and ignored (before, it marked the
>   current subscriber canceled), and so are `.created`/`.updated`, the invoice re-sync and
>   `invoice.payment_failed` for another id **while the stored subscription is still live**
>   (`isReplaced()`; Copilot catch on #250). A new subscription after the stored one has
>   ended still syncs.
> - The message names the plan Stripe is ending (`User.subscriptionTier`), not the effective
>   tier: a comp above a canceled Loop plan outlasts it (Copilot catch on #250).
> Covered by `tests/Service/SubscriptionServiceStripeTest` (cancel_at, cancel_at_period_end,
> resume), `tests/Service/SubscriptionServiceTest::testScheduledEnd` and
> `tests/E2E/SubscriptionEndDateTest` (a signed update shows the date, a renewing plan shows
> none, a replaced subscription's late update or deletion is ignored, a new one after the old
> ended syncs, the message names the billed plan under a comp).

> **Self-hosted instances have no billing (`APP_HOSTING_MODE=self`, 2026-10-12, PR 6a).**
> `App\Hosting\HostingMode` reads `APP_HOSTING_MODE` (`hosted`, the `.env` default, or
> `self`; anything else **including a blank value** is a startup error, so an emptied
> variable can't silently turn billing on — Copilot catch on #245). On a self-hosted copy:
> - `SubscriptionService::effectiveTier()` returns **Loop+ for every account**, so every
>   feature gate passes and both allowances (`AnalysisAllowance`, `CheckinAllowance`) are
>   unlimited — they already ask `hasFeature()`. Unknown feature keys still deny.
> - The pricing (both URLs), checkout, success/cancel, portal, Settings ▸ Subscription and
>   Stripe-webhook routes carry `condition: "service('hosting').isHosted()"`
>   (`#[AsRoutingConditionService(alias: 'hosting')]`): they **don't match, so they 404**,
>   while `path()` still generates them for templates — removing the routes instead would
>   make every upsell template throw. The upsells themselves disappear because every
>   feature passes; the hub drops its Billing category (`SettingsController::index`), the
>   sitemap leaves out pricing in every language, and `/about`'s "plans" sentence (a pricing
>   link) is hidden via the `hosting` Twig global — public pages never advertise the 404
>   (Copilot catch on #245).
> - The company pages, legal pages, analytics, name and logo on a self-hosted copy are
>   `branding.md` (PR 6b); the admin user list hides its plan actions there.
> Covered by `tests/Hosting/HostingModeTest` and `tests/E2E/SelfHostedModeTest` (which sets
> `APP_HOSTING_MODE` / `DEFAULT_URI` per test before booting the kernel).
Paid tiers via **Stripe** (web-direct Checkout; no App/Play-store IAP). The full flow is
built: hosted **Checkout** + **Billing Portal**, a signature-verified **webhook**, a public
**pricing page**, per-feature **gating** on existing services, and a **7-day trial** for new
subscribers. SDK: **`stripe/stripe-php`** (raw, no Symfony bundle), one shared
`Stripe\StripeClient` configured from `STRIPE_SECRET_KEY` in `services.yaml`.

> **Live in production (as of the v1.0.0 launch).** The Stripe dashboard is configured
> (products + prices + Billing Portal + webhook endpoint) and the **real `STRIPE_*` values
> are set in the server's `.env.local`** (secret key, webhook secret, the price IDs — two
> since PR 7, which need new Stripe Prices before it deploys).
> Paid upgrades work end-to-end; this is no longer a pending deploy item.

- **Env / config:** `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`,
  and the two price IDs (`STRIPE_LOOP_MONTHLY_PRICE_ID`, `STRIPE_LOOP_PLUS_MONTHLY_PRICE_ID`;
  the yearly pair was retired in PR 7). Real (test/live) values live in the untracked
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
- **`SubscriptionController`:** `/pricing` (PUBLIC_ACCESS, two monthly tier cards `#plan-loop`
  / `#plan-loop-plus` with descriptor lines — the annual toggle was retired in PR 7 —
  current-plan badge; above them a **Free strip** — `#free-plan-strip`,
  `pricing_page.free_strip`/`free_cta`, 2026-10-08 — stating Free's two allowances from the
  same constants as `free_note`, with a gold "Create a free account" button for visitors, the
  current-plan badge for Free users, and nothing for paying users; pinned by
  `tests/E2E/FirstVisitCopyTest`), `POST /subscription/checkout` (CSRF `subscription-checkout`,
  **validates `price_id` against the two known prices** before calling Stripe),
  `/subscription/success` (fast-path sync), `/subscription/cancel-checkout` (PUBLIC_ACCESS,
  bounce to pricing), `POST /subscription/portal` (CSRF `subscription-portal`), and
  `/settings/subscription` (per-user page: current plan + "Manage subscription" portal button
  for existing customers). Return/success URLs are built absolute via the router.
- **Feature gates wired in:**
  - **Monthly analysis allowance** — `FoodAnalysisService::analyzeImage(User, …)` throws
    `App\Exception\PhotoLimitExceededException` (caught in every analysis controller,
    which flashes and redirects where the exception's `AllowanceKind` says) once the user
    has used their allowance in their local calendar month (who has one, and how big: the
    PR 7 note at the top of this section). The count is
    ONE shared bucket in `App\Service\AnalysisAllowance`: meals logged this month
    (`MealEntryRepository::countForUserThisMonth`) + recipe photo imports this month
    (`FavoriteRepository::countRecipeImportsForUserThisMonth`) + **meal re-analyses and
    supplement label scans this month** (`AnalysisEventRepository::countForUserThisMonth`
    — the call-time ledger; see Meal history & editing ▸ Phase 3 and Supplements ▸ Cost
    controls) — see Recipe photo import under Favorite meals.
  - **CGM correlation** (`cgm_correlation`, both paid tiers) — gated in `MealController::show` (locked
    upsell card when glucose data exists but the user isn't entitled) and
    `GlucoseInsightsController` (whole page → upgrade prompt).
  - **AI Pattern Coach** (`ai_pattern_coach`, both paid tiers) — **dual-gated** in
    `GlucoseInsightsService::forUser`: the global kill switch **AND**
    `hasFeature(user, 'ai_pattern_coach')`. (The per-user `glucoseAiEnabled` third
    gate was retired 2026-07-24 — see Glucose insights ▸ Higher-cost AI tier.)
- **`has_feature('…')` Twig function** (`App\Twig\SubscriptionExtension`) gates UI (e.g. the
  nav **Upgrade** link, shown only to Free users via `paid_plan`); false when nobody is
  logged in.
- **ToS:** a "Subscription and billing" section (tiers/pricing via `subscription_price()`,
  monthly auto-renewal, 7-day trial, refunds, cancellation, 30-day price-change notice) is in `templates/privacy/terms.html.twig`.
- Covered by `tests/Service/SubscriptionServiceStripeTest` (price→tier + sync mapping),
  `tests/Twig/SubscriptionExtensionTest`, and `tests/E2E/SubscriptionPagesTest` (public
  pricing renders, webhook rejects a forged signature). **No browser E2E for the paid flow** —
  exercise Checkout manually with the Stripe CLI (`stripe listen --forward-to
  localhost:8000/webhook/stripe`, `stripe trigger …`) + test card `4242…`.

- **Tiers** (`App\Enum\SubscriptionTier`): `Free` / `Loop` / `Loop+`. `hasFeature(string)`
  centralises gating — the keys and who has them are in the PR 7 note at the top of this
  section (before it, Loop+ alone had the daily/delivery/CGM/coach/predictive keys). Unknown
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
  `subscription_period_end`, `trial_ends_at`, and (since PR 8) `subscription_cancel_at`. (Admin user-delete needs no change — all
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
