# Security Policy

NouriLoop is a nutrition-tracking app. NouriLoop LLC runs it as a hosted service at
**https://app.nouriloop.com**, where it handles food logs, health metrics, and account data
for real users and takes payments through Stripe. It is also open-source software that
anyone can run themselves. Reports about the hosted service or the software are welcome
and taken seriously.

## Supported Versions

There are no maintenance branches. Security fixes go forward into the next release,
never back into an old one.

| Version | Supported |
| ------- | ------------------ |
| The release currently deployed to app.nouriloop.com | :white_check_mark: |
| The latest release, on a self-hosted copy | :white_check_mark: |
| Any earlier release | :x: |

The deployed release is shown in the footer of the site. If you run your own copy,
updating to the latest release is how you get security fixes
([docs/self-hosting.md](docs/self-hosting.md) says how).

**A self-hosted copy is run by its operator, not by NouriLoop LLC.** A flaw in the
NouriLoop software is in scope wherever you found it; report it here. A problem with
one particular copy (its server, its settings, its data) goes to that copy's operator,
whose address is in the copy's `/.well-known/security.txt`.

## Reporting a Vulnerability

**Email `info@nouriloop.com`** with `Security:` at the start of the subject line.
That address is monitored by the developer directly.

Please include enough to reproduce the issue: the URL or endpoint, the steps, what
you expected, what actually happened, and any proof-of-concept request or payload.
A short screen recording is often faster than prose. Tell us if you intend to
publish, and when.

### What to expect

NouriLoop is built and run by **one person**. These timelines are what can actually
be honored, not aspirational ones:

| Stage | Target |
| ----- | ------ |
| Acknowledgement that your report arrived | 3 business days |
| Initial assessment — accepted, declined, or need more detail | 10 business days |
| Fix deployed for an accepted critical issue | As fast as possible; you'll get a date once it's triaged |

There is no 24/7 on-call rotation. If you believe user data is being actively
exposed right now, say so in the subject line — that changes the priority.

**If it's accepted:** you'll get a fix timeline, and a note when the fix is
deployed. Credit in the release notes if you want it — tell us the name or handle
to use, or say you'd rather stay anonymous.

**If it's declined:** you'll get a reason, not silence. Usually that means it's out
of scope below, it's a known accepted risk, or the impact can't be reproduced. If
you disagree, say so — a declined report reopened with a better proof-of-concept is
a normal outcome, not a nuisance.

**There is no bug bounty.** No monetary rewards are offered, and none should be
inferred from this policy. Reports are still genuinely appreciated.

## Scope

**In scope:** `nouriloop.com` and its subdomains, the web application, its
authentication and session handling (including magic-link sign-in and password
reset), the billing and subscription flows, any exposure of one user's data to
another, and the self-hosting package (the Docker image, the Compose stack and its
default settings).

**Out of scope:**

- Third-party services and their infrastructure — Stripe, Anthropic, the mail
  provider, the VPS host. Report those to the vendor.
- Denial of service, volumetric testing, or anything that degrades the service for
  real users.
- Social engineering, phishing directed at staff or users, and physical attacks.
- Raw automated-scanner output with no demonstrated impact.
- Missing hardening headers, cookie flags, or TLS configuration preferences with no
  demonstrated exploit path. Interesting as a note; not a vulnerability report.
- Vulnerabilities requiring a compromised device, a rooted browser, or an attacker
  who already has the victim's credentials.

## Rules for testing

**Real people's health and nutrition data is in this system.** That constrains what
responsible testing looks like:

- Test against **your own account only**. Registration is open and free — make one.
  Better still, test against your own self-hosted copy, where there's no one else's data
  to reach.
- Never test against someone else's self-hosted copy without its operator's permission.
- **Do not access, modify, download, or retain another user's data.** If you access
  someone else's data by accident, stop immediately, don't save it, and tell us what
  you saw in your report.
- No destructive testing, no load or stress testing, no automated scanning that
  generates significant traffic.
- Don't send messages, emails, or notifications to other users.
- Use the minimum proof necessary. Demonstrating that you *can* read a record is
  enough; enumerating every record is not, and turns a report into an incident.

Testing that stays inside these rules will not be pursued as a complaint, and we'll
work with you on the report. Testing that ignores them — particularly bulk access to
other users' data — is a different conversation.

## ⚠️ Note on where this file is reachable

NouriLoop's source, this file included, is public at
[john-c-houser-tech/nouriloop-app](https://github.com/john-c-houser-tech/nouriloop-app)
and ships with every copy, self-hosted ones too. A researcher probing the site has no
reason to go looking there, though.

This file therefore documents the process, but it is **not** the main way for an
outside researcher to find out how to reach us.

**The published disclosure path is the RFC 9116 file at
`https://app.nouriloop.com/.well-known/security.txt`** (added 2026-08-10), served by
`SecurityTxtController` and carrying the contact address plus the essential testing
rules inline — because a researcher who reaches it can read nothing else we have
written, including this file.

⚠️ **That route is the real policy as far as the outside world is concerned.** If it
is ever removed, or allowed to fall behind the firewall, or left to lapse past its
`Expires` date, NouriLoop is back to having no discoverable security contact no
matter what this document says. `SecurityTxtTest` guards all three.
