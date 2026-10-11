# NouriLoop

Close the loop between what you eat and how you feel.

NouriLoop is a nutrition tracker built around food photos. You photograph a meal, Claude
estimates the nutrition and ingredients, and the app connects that over time to your health
metrics (CGM glucose, weight, sleep, and more via Apple Health exports) and to the symptoms
you choose to track. It runs as a hosted service at **https://nouriloop.com**; this
repository is its full source.

NouriLoop is an educational wellness tool, not a medical device. Its scores and insights are
not medical advice or diagnosis.

## Stack

- Symfony 7.4 on PHP ≥ 8.2 (with `intl` and `imagick`), Doctrine ORM, Twig, Bootstrap 5
- MariaDB
- Anthropic's Claude API for photo analysis, goal coaching, and the help assistant
- Stripe for subscriptions, Twilio for optional SMS

## Running it locally

```bash
composer install
# .env.local (untracked): APP_SECRET, DATABASE_URL, and optionally ANTHROPIC_API_KEY
php bin/console doctrine:migrations:migrate
symfony serve
```

Mail goes to whatever `MAILER_DSN` points at; `.env.dev` expects a catcher such as Mailpit on
`127.0.0.1:1025`. Without `ANTHROPIC_API_KEY` the app runs, but analysis features fail.

Tests: `vendor/bin/phpunit` (unit/functional) and `vendor/bin/phpunit -c phpunit.e2e.xml.dist`
(browser E2E). Code style: `composer cs-check`.

## Where to read next

- `CLAUDE.md`: the detailed developer notes, section by section (written for both people and
  coding agents)
- `CONTEXT.md` and `docs/adr/`: domain glossary and architecture decisions
- `FEATURES.md`: what the app does, from a user's point of view
- `SECURITY.md`: how to report a vulnerability

## License

Copyright © 2026 NouriLoop LLC. The source code is licensed under the
[GNU Affero General Public License v3.0](LICENSE) or later. If you run a modified version
as a network service, the AGPL requires you to offer your users its source.

The NouriLoop name, logo, and other brand assets are trademarks of NouriLoop LLC and are not
covered by the license. A fork must use its own name and branding.
