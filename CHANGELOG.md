# Changelog

What changed in each release, newest first. Dates are when the release went live on the
hosted service (app.nouriloop.com since 3.2; nouriloop.com before). The format follows [Keep a Changelog](https://keepachangelog.com/), and
versions follow [Semantic Versioning](https://semver.org/) loosely: a minor version adds
something you can see, a patch fixes something.

Changes to the developer notes, deployment and the project's own operations aren't
listed.

## Unreleased

### Security
- Symfony updated within 7.4 for three advisories: a ReDoS in `symfony/http-foundation`
  reachable through the `Cache-Control` request header (CVE-2026-108082), unbounded recursion
  in the `symfony/yaml` inline lexer (CVE-2026-109413), and unbounded nesting in the
  `symfony/expression-language` parser (CVE-2026-109449).

## 3.2.1 - 2026-10-20

### Fixed
- Replying to a feedback email now goes only to the person who sent the feedback, not also
  to the no-reply address.

## 3.2 - 2026-10-18

### Changed
- **New address:** the hosted service is now at **app.nouriloop.com**. nouriloop.com becomes
  NouriLoop LLC's business site.
- **Self-hosting guide:** how to restore a backup, a note to open ports 80 and 443 in your
  cloud provider's firewall, and which start-up warnings are expected. The settings file
  written by `scripts/selfhost-init.sh` no longer begins with "don't edit this file".

## 3.1 - 2026-10-16

### Added
- **Self-hosting package:** a Docker image, a Compose stack (app, scheduler for the timed
  jobs, MariaDB), a settings file with an init script, and a step-by-step guide in
  `docs/self-hosting.md`. Tagged releases publish a ready-to-unpack archive.
- `CONTRIBUTING.md`, issue forms, and this changelog.

### Changed
- `app:make-admin` also marks the admin's email address as verified, so a copy without
  email can clear the "confirm your email" banner.
- `app:ai-costs` labels the instance's own spend with its own name.

## 3.0 - 2026-10-14

### Added
- **Bring your own AI key:** Settings ▸ AI provider takes your own Anthropic key, OpenRouter
  key, or any OpenAI-compatible service, with separate text and photo models. Keys are
  stored encrypted and never shown again.
- **Photo model check:** five reference photos test whether a chosen photo model reads
  labels and estimates portions well enough. A model that misses gets a warning on the
  meal review, not a block.
- **OpenAI-compatible AI providers** for a whole instance (`AI_PROVIDER`), alongside
  Anthropic.
- A per-call record of AI tokens and estimated cost, and `app:ai-costs` to report it.
- **Self-hosted mode** (`APP_HOSTING_MODE=self`): no billing, every feature for every
  account, and the copy's own name, logo, address and legal pages.
- **Loop+ top-up:** $1.75 for 60 more meal analyses in the current month.

### Changed
- **New plans:** Loop is $2 a month with your own AI key and every feature except SMS;
  Loop+ is $13.50 a month with AI included, 120 meal analyses and SMS. Monthly only.
- Subscribers switch plans in the billing portal; a customer has one subscription.
- Re-logging a favorite meal no longer counts against the analysis allowance.

### Fixed
- A photo that can't be read is dropped instead of failing the whole analysis.
- Settings shows when a canceled plan ends.
- A second checkout can't start while a subscription is incomplete.

## 2.18.1 - 2026-10-10

### Changed
- Security notes and `security.txt` updated now that the source is public.

## 2.18.0 - 2026-10-10

### Changed
- The source is published under the AGPL as `nouriloop-app`.

## 2.17.0 - 2026-10-08

### Changed
- Public pages: one tagline, toned-down claims, an honest savings badge, and privacy copy
  that matches what the app actually shares.
- The cookie banner names the Meta Pixel; meal review discloses what refine rounds send.

### Fixed
- Russian pricing text for a single check-in.

## 2.16.1 - 2026-10-07

The same code as 2.16.0, tagged again.

## 2.16.0 - 2026-10-07

### Changed
- All health imports (Apple Health XML, JSON, CSV, API) store readings the same way: the
  same rounding, the same refusal of implausible values, and clearer reports of what was
  skipped and why.
- Daily intake is calculated in one place, so every screen agrees. Supplements count from
  the day they were added.
- Meal and recipe reviews share one question-and-answer flow; saving keeps ingredient
  edits.

### Fixed
- A password-reset email that fails to send no longer causes an error page.
- Disabled accounts no longer get scheduled messages.
- A nutrient the AI couldn't estimate is shown as unknown, not as zero.
- A rejected photo says it isn't an image, instead of blaming HEIC.

## 2.15.2 - 2026-09-30

### Fixed
- Date ranges, input limits and many small edge cases; faster dashboard and glucose
  queries.

## 2.15.1 - 2026-09-30

### Added
- Glucose imports in mmol/L, and units and steps in CSV imports.

### Fixed
- Scheduled notifications and goal check-ins: one user's or goal's failure no longer stops
  the rest.
- Account deletion also removes API tokens and files.

### Security
- The health import form has a CSRF token.
- Checkout confirmation checks that the session belongs to the signed-in user.

## 2.15.0 - 2026-08-10

### Security
- `SECURITY.md` and an RFC 9116 `security.txt`.

## 2.14.2 - 2026-08-02

### Fixed
- Warnings were logged twice.

## 2.14.1 - 2026-08-01

### Fixed
- The navigation bar no longer wraps at iPad widths.

## 2.14.0 - 2026-08-01

### Added
- Onboarding knows your device for the first meal, with "continue on my phone".

## 2.13.0 - 2026-07-29

### Added
- **Meal re-analysis:** ask the AI to look at a logged meal again and see the differences
  before applying them.

## 2.12.0 - 2026-07-26

### Changed
- Help says which phone features actually need one.

## 2.11.0 - 2026-07-25

### Added
- Registration records where new users came from (first-touch campaign tags), for the
  admin.

## 2.10.0 - 2026-07-24

### Added
- Settings ▸ Profile: change your name and sign-in email (confirmed by email first).
- Registration warns about likely typos in an email address.
- Admins can upgrade or downgrade a user's plan.

## 2.9.0 - 2026-07-24

### Added
- Edit a logged meal's nutrients by hand (with a record of what was edited), rescale the
  portion, or move it to another day.

## 2.8.4 - 2026-07-23

### Changed
- Goal check-ins explain what "on track" means.

## 2.8.3 - 2026-07-22

### Changed
- The pricing page is accurate about favorites on the Free plan.

## 2.8.2 - 2026-07-22

### Added
- Help and public pages for recipe import.

## 2.8.0 - 2026-07-22

### Added
- **Recipe import:** photograph a recipe and save it as a per-serving favorite, with its
  steps.

## 2.7.0 - 2026-07-22

### Added
- **Meal history:** a history list, saving a past meal as a favorite, and edits that keep
  the original analysis.

## 2.6.0 - 2026-07-22

### Added
- First-run polish: dashboard previews, a first-meal success moment, a notification
  preset, and clearer empty states.

## 2.5.2 - 2026-07-21

### Changed
- The Settings & Notifications help topic covers the whole settings hub.

## 2.5.1 - 2026-07-21

### Changed
- Pricing shows "What If" predictions as available.

## 2.5.0 - 2026-07-21

### Added
- Goal check-ins and notifications by plan: Free gets six check-ins, Loop weekly, Loop+
  daily, plus push delivery.

## 2.4.0 - 2026-07-21

### Added
- Sign-up improvements: signed in straight after registering, live password feedback, and
  starter goals.

## 2.3.0 - 2026-07-21

### Added
- **Meal logging overhaul:** several photos per meal, describe a meal in words instead,
  a step-by-step review, a visible analysis allowance, and camera capture.
- Notes for the AI, automatic timezone detection with a repair banner, and a retry when an
  analysis fails.

## 2.2.0 - 2026-07-19

### Added
- `app:grant-comp` to give an account a complimentary plan from the command line.

## 2.1.0 - 2026-07-19

### Added
- A weight-loss landing page.

## 2.0.0 - 2026-07-18

### Added
- **Languages:** English, Spanish, German, French, Russian and Hindi, with British English,
  Spain and Mexico variants. Dates and numbers follow the language; AI replies, emails and
  messages arrive in each user's language; the help assistant searches in every language.

## 1.9.0 - 2026-07-15

### Added
- Landing pages for diabetes, inflammation and fiber.

## 1.8.0 - 2026-07-14

### Changed
- The sign-in page separates returning and new users, with a clear sign-up button.

## 1.7.1 - 2026-07-14

### Changed
- The session cookie has an app-specific name.

## 1.7.0 - 2026-07-13

### Changed
- The contact address is info@nouriloop.com.

## 1.6.0 - 2026-07-10

### Added
- The admin user list shows each user's plan and filters by plan, activity and status.

### Changed
- Beta codes are called coupons.

## 1.5.3 - 2026-07-06

### Fixed
- Accepting the cookie banner didn't grant ad consent.

## 1.5.2 - 2026-07-05

### Changed
- The welcome email's confirm button moves to a P.S.

## 1.5.1 - 2026-07-05

### Fixed
- The confirm link did nothing for a user who was already signed in.

## 1.5.0 - 2026-07-05

### Added
- **Email verification:** a banner until the address is confirmed. The app stays fully
  usable; scheduled email waits for confirmation.

## 1.4.0 - 2026-07-05

### Added
- **Sign-in links:** sign in from an emailed link, without a password.

## 1.3.0 - 2026-07-05

### Added
- Stay signed in for 180 days, a show/hide password toggle, and a check against known
  breached passwords.

## 1.2.0 - 2026-07-03

### Added
- A guided setup for connecting a Lingo or Stelo glucose sensor.

## 1.1.3 - 2026-07-03

### Security
- Uploaded files get unguessable names.

## 1.1.2 - 2026-07-01

### Added
- The Meta Pixel on public pages only, after cookie consent.

## 1.1.1 - 2026-07-01

### Changed
- The symptoms help page shows an example relationship chart and explains significance.

## 1.1.0 - 2026-07-01

### Added
- **Account deletion** with a grace period, and the Settings hub.
- Admin user management with search and pages.

### Changed
- Symptom charts show one small chart per symptom instead of overlaid lines.

## 1.0.0 - 2026-06-28

First public release: registration opened to everyone after a private beta.
