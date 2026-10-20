---
description: "Open sign-up, coupons and beta codes, email deliverability warnings, first-touch UTM attribution and its counts endpoint, and the feedback form, and the business-site contact form."
paths:
  - "src/Controller/RegistrationController.php"
  - "src/Form/RegistrationFormType.php"
  - "src/Service/InvitationCodeManager.php"
  - "src/Entity/InvitationCode.php"
  - "src/Repository/InvitationCodeRepository.php"
  - "src/Service/EmailDeliverabilityChecker.php"
  - "src/Service/AttributionCapture.php"
  - "src/EventSubscriber/AttributionSubscriber.php"
  - "src/Controller/Api/MetricsApiController.php"
  - "src/Controller/FeedbackController.php"
  - "src/Form/FeedbackFormType.php"
  - "src/Service/TurnstileValidator.php"
  - "templates/registration/**"
  - "templates/feedback/**"
  - "src/Controller/SiteContactController.php"
  - "templates/email/site_contact.html.twig"
---

# Registration, attribution and feedback — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

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

### Business-site contact form (`POST /site-contact`, 2026-10-20)
The LLC business site at **nouriloop.com** is static (GitHub Pages, the public
`nouriloop-site` repo), so its "Book a conversation" form has no server of its own: a plain
HTML `<form method="post">` posts cross-site to **`https://app.nouriloop.com/site-contact`**
(`SiteContactController`, `^/site-contact$` is `PUBLIC_ACCESS`). This replaced a `mailto:`
link (which opened whatever the visitor's browser maps mail links to, IONOS webmail on
John's machine) and meets Transformation Plan §3A item 4: delivery that doesn't depend on
IONOS. **Hosted only** (route `condition: service('hosting').isHosted()`, so a self-hosted
copy answers 404).
- Fields `name` (optional, 200), `email` (required, valid), `message` (required, 5000), plus
  the honeypot `website`. Read straight off the request, **no Symfony form and no CSRF
  token** (the page is on another origin and has no session). Mailed to **`SITE_CONTACT_TO`**
  (`.env` default `info@nouriloop.com`), subject "Business site: {name}", Reply-To the
  visitor, template `email/site_contact.html.twig` (team-facing, English, names the LLC's
  site literally). Not persisted.
- **Every outcome is a 303 back to the static site** at `SITE_CONTACT_ORIGIN` (`.env`
  default `https://nouriloop.com`): success and the honeypot → `/contact/thanks/`; bad input,
  the cap or a mail failure → `/contact/problem/?reason=invalid|limited|error`. Those two
  pages live in `nouriloop-site`; keep the paths in step.
- **Defenses:** the honeypot, the **`site_contact` limiter** (5/hour per client IP), and an
  **Origin check** — an `Origin` that isn't `SITE_CONTACT_ORIGIN` gets a 403, **including
  the opaque `null`** a sandboxed iframe sends (a Copilot review catch: accepting it let a foreign
  page post through a visitor's browser and spend their per-IP allowance). Only an absent
  header passes; a bot can forge the header anyway, so this only stops other sites' pages
  from using visitors' browsers to post the form. **No Turnstile**: the business site loads no third-party
  script. The recipient is fixed, so the worst abuse is spam to our own inbox.
- Covered by `tests/E2E/SiteContactTest` (send + sole Reply-To, honeypot, invalid input,
  foreign and `null` Origin 403, the sixth-in-an-hour cap, GET 405, self-hosted 404).
