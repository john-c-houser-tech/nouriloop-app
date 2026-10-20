---
description: "Login, magic links, email verification, remember-me, access control, password reset, the mailer setup, and how CSRF actually works here."
paths:
  - "src/Security/**"
  - "src/Controller/SecurityController.php"
  - "src/Controller/LoginLinkController.php"
  - "src/Controller/EmailVerificationController.php"
  - "src/Controller/ResetPasswordController.php"
  - "src/Service/EmailVerifier.php"
  - "src/Form/LoginLinkRequestFormType.php"
  - "src/Form/ResetPasswordRequestFormType.php"
  - "src/Form/ChangePasswordFormType.php"
  - "src/Entity/ResetPasswordRequest.php"
  - "config/packages/security.yaml"
  - "config/packages/csrf.yaml"
  - "config/packages/mailer.yaml"
  - "config/packages/framework.yaml"
  - "templates/security/**"
  - "templates/reset_password/**"
  - "templates/email/**"
  - "templates/_password_toggle.html.twig"
  - "templates/_email_verification_banner.html.twig"
---

# Auth, password reset, mail delivery and CSRF — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

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
  One non-inbox stamp: **`app:make-admin`** verifies on grant (server access vouches;
  mailer-less self-hosts need it — see `admin.md`).
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
- **Welcome email:** (⚠️ hosted service only — a self-hosted copy sends the translated
  verification email instead, since the letter is John's personal note; `branding.md`.)
  On a successful registration `RegistrationController::sendWelcomeEmail`
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

## Email delivery (Mailpit dev / Resend prod)
Applies to all mail (password reset, notifications, new-user admin notice, welcome email).

- **Dev:** `mailpit` catcher — `MAILER_DSN=smtp://localhost:1025` (`.env.dev`),
  view at http://localhost:8025 (`brew install mailpit`).
- **Prod (since 2026-10-17):** the app sends straight to **Resend** over SMTP —
  `MAILER_DSN=smtp://resend:<key>@smtp.resend.com:587` in the server `.env.local`, with a
  sending-only key restricted to the domain — from the subdomain **`mail.nouriloop.com`**,
  so app-mail reputation stays apart from the root domain's human mail. SPF/DKIM/DMARC
  pass, aligned to `mail.nouriloop.com` (verified on a live `mailer:test`). Server-side
  details are in `nouriloop-ops`' `docs/ops/production.md`. *(Before: Postfix on the VPS
  relayed to IONOS as the `john@nouriloop.com` mailbox, `MAILER_DSN=sendmail://default`,
  2026-06-21 → 2026-10-17. The VPS provider blocks port 25, which is why a relay was needed.)*
- **From address** is a global default header (`framework.mailer.headers.From`)
  driven by **`MAILER_FROM`** (`.env` / `.env.local`) — no per-message `->from()`.
  It must be an address the mail provider lets you send as: with Resend, any address on a
  verified domain. **Prod sends as `NouriLoop <noreply@mail.nouriloop.com>`.** The public
  contact address stays **`info@nouriloop.com`** (an alias of the `john@nouriloop.com`
  mailbox): the repo's `.env` default and every public-page contact mention use it, **except
  the welcome email's letter sign-off, which deliberately stays `john@nouriloop.com`** (it's
  a personal note from John — `WelcomeEmailTest` pins this). (History: the sender domain was
  `@johnchouser.com` until 2026-06-21, `john@nouriloop.com` until 2026-07-13, `info@` via
  the IONOS relay until 2026-10-17.)
- **Reply-To** is a second global header (`framework.mailer.headers.Reply-To` =
  **`MAILER_REPLY_TO`**, `.env` default **`noreply@nouriloop.com`**; an env var since
  2026-10-12 so a self-hosted copy can use its own domain), unlike `From` it need not be
  the authenticated mailbox — it just steers replies to an unmonitored box so they don't
  land in the `From` mailbox.
  It is a **default, not an addition** (2026-10-20): Symfony's own rule for Reply-To is
  "add", so a message that set its own Reply-To (the feedback and business-site contact
  forms) went out replying to `noreply@` **as well as** the submitter. `src/Kernel.php`'s
  compiler pass changes the `mailer.message_listener` rule to set-if-empty; pinned by
  `FeedbackTest` (submitter only), `SiteContactTest`, and `WelcomeEmailTest` (default kept).
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
