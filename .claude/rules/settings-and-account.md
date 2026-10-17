---
description: "The signed-in navbar's breakpoints, the /settings hub, profile and email change, and the grace-period account deletion."
paths:
  - "templates/base.html.twig"
  - "src/Controller/SettingsController.php"
  - "src/Form/ProfileFormType.php"
  - "src/Service/AccountDeletionService.php"
  - "src/Command/PurgeDeletedAccountsCommand.php"
  - "src/Twig/AccountDeletionExtension.php"
  - "templates/settings/**"
  - "templates/_deletion_banner.html.twig"
---

# Navbar, settings hub, profile and account deletion — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

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
  - **`user_ai_settings`** (a user's own AI key, see `ai-client.md`) goes with the
    account through its `ON DELETE CASCADE` FK; "start fresh" keeps it, like API tokens.
  - **`ai_call` is the deliberate exception to "every per-user table gets a delete"**
    (John's call, 2026-10-12): its `user_id` is `ON DELETE SET NULL`, so purging an account turns its
    AI-call cost rows into anonymous ones instead of deleting them. They hold no content
    (feature, model, tokens, a time), and keeping them keeps the cost totals the Full
    price is checked against. "Start fresh" leaves them too. To delete instead, add
    `ai_call` to `deleteUserRows` (see `ai-client.md`).
  - **`purgeUser(User)`** — the **full account wipe** (every row scoped to the user,
    **its API tokens**, and the user row itself in one transaction; uploaded files are
    unlinked only **after** it commits, so a rolled-back purge leaves a live account
    with its photos intact — `purgeUserData` got the same ordering). ⚠️ `api_token` is
    deleted here, **not** in the shared `deleteUserRows`: "start fresh" keeps tokens.
    `allowance_top_up` (paid Loop+ top-ups, `billing.md`) is deleted here too, for the
    same reason: "start fresh" keeps billing state.
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
