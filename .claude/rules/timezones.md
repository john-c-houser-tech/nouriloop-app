---
description: "Per-user timezone, the wall-clock storage model, UserTime, half-open day ranges, and server-frame scheduling instants."
paths:
  - "src/Service/UserTime.php"
  - "src/Service/TimezoneChanger.php"
  - "src/Twig/TimeExtension.php"
  - "src/Form/TimezoneFormType.php"
  - "src/Service/GoalScheduler.php"
  - "src/Repository/**"
  - "templates/_timezone_banner.html.twig"
  - "templates/settings/timezone.html.twig"
---

# Timezones and the user-local storage model — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

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
