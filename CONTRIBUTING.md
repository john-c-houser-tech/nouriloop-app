# Contributing

Thanks for wanting to help. NouriLoop has one maintainer, so a little planning up front
saves both of us time.

## Before you start

- **Small fixes** (a typo, a clear bug with an obvious fix): just open a pull request.
- **Anything bigger** (a new feature, a change to how something works, a new dependency):
  open an issue first and describe what you have in mind. It's disappointing to build
  something and then find it doesn't fit.
- **Security problems:** please don't open an issue. Follow [SECURITY.md](SECURITY.md)
  instead.

## Setting up

The [README](README.md#running-it-locally) covers running the app. For the tests you also
need:

- a MariaDB database for the test environment: put a `DATABASE_URL` in an untracked
  `.env.test.local`. The test environment adds `_test` to the database name, so
  `.../nutrition` there means the database `nutrition_test`. Create its tables with
  `php bin/console doctrine:migrations:migrate --env=test -n`;
- the upload folders the tests write to:
  `mkdir -p public/uploads/{meals,supplements,favorites,goal-charts}`;
- `ANTHROPIC_API_KEY` set to any value, for example `ANTHROPIC_API_KEY=test-key`. The test
  environment never calls a real AI service: every request is answered by
  `tests/Http/FakeAnthropicResponder`.

`compose.yaml` is the self-hosting package, not a development environment.

## Before you open a pull request

Run what CI runs:

```bash
composer cs-check                              # PSR-12 code style (composer cs-fix repairs most of it)
php bin/console lint:container
vendor/bin/phpunit                             # unit and functional tests
vendor/bin/phpunit -c phpunit.e2e.xml.dist     # browser tests (needs Chrome; see below)
```

The browser tests need a Chrome install and a matching driver, which
`vendor/bin/bdi detect drivers` downloads into `./drivers/`. If you can't run them, say
so in the pull request; CI runs them for every pull request.

Add or update tests for what you change.

## House rules

These catch most first pull requests, so they're worth reading before you write code.

- **Update the developer notes in the same pull request.** `CLAUDE.md` holds what applies
  everywhere, and `.claude/rules/` holds one file per area of the app (the table in
  `CLAUDE.md` says which file covers what). If your change alters how something behaves,
  update the file that describes it.
- **New text appears in every language.** User-facing text lives in `translations/`, and
  each new English message needs a matching entry in every full catalog (es, de, fr, ru,
  hi). `CatalogConsistencyTest` fails otherwise. If you can't translate, say so in the
  pull request.
- **Never write "NouriLoop" in text the app shows.** Self-hosted copies carry their own
  name. Use `{app_name}` in translations, `{{ brand.name }}` in templates, and
  `Brand::name()` in PHP.
- **AI calls go through `App\Ai\AiClient`**, never a provider class directly, and name the
  feature they're for (`AiFeature`).
- **Health data never reaches analytics.** No glucose, weight, symptom or meal data goes to
  Google Analytics or the Meta Pixel.
- **Times are the user's own.** Anything about "today" or a date range goes through
  `App\Service\UserTime`.

Pull requests are reviewed by the maintainer, who also merges them.

## Using a coding agent

`CLAUDE.md` and `.claude/rules/` are written for coding agents as well as people, so an
agent working in this repository will find the same rules. You're responsible for what you
submit, however it was written.

## License

NouriLoop is licensed under the [GNU Affero General Public License v3.0 or later](LICENSE).
By opening a pull request, you agree that your contribution is licensed under the same
terms, and you confirm you have the right to submit it. There's no separate agreement to
sign.

The NouriLoop name and logo are trademarks of NouriLoop LLC and aren't covered by the
license (see the [README](README.md#license)).
