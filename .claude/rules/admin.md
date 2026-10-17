---
description: "The admin user list (search, filters, row actions), tier Upgrade/Downgrade, reports, and the admin CLI commands."
paths:
  - "src/Controller/AdminUserController.php"
  - "src/Controller/AdminReportController.php"
  - "src/Dto/AdminUserFilters.php"
  - "src/Command/MakeAdminCommand.php"
  - "src/Command/GrantCompCommand.php"
  - "templates/admin/**"
---

# Admin and user management — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

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
  breakdown (`FavoriteRepository::savingsByUser`). **AI spend** is a CLI report,
  `php bin/console app:ai-costs [--month=YYYY-MM]` — see `ai-client.md` ▸ The cost record.
- **Grant admin:** `php bin/console app:make-admin <email>` (`--revoke` to remove).
  Granting also stamps email verification (`EmailVerifier::markVerified`, a no-op if
  already verified; revoking leaves it): running it on the server vouches for the
  account, and a mailer-less self-host has no other way to clear the banner.
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
