# Security Policy

NouriLoop is a hosted nutrition-tracking service at **https://nouriloop.com**. It
handles food logs, health metrics, and account data for real users, and it takes
payments through Stripe. Reports about any of that are welcome and taken seriously.

## Supported Versions

NouriLoop is **a hosted service first.** The source is public, but there is one
supported production instance, self-hosted copies aren't supported, and there are no
maintenance branches.
So the usual version-support matrix doesn't apply — there is only ever one version
running, and security fixes reach users by deploying forward, never by backporting.

| Version | Supported |
| ------- | ------------------ |
| The release currently deployed to nouriloop.com | :white_check_mark: |
| Any earlier tag | :x: |

The deployed release is shown in the footer of the site. Earlier tags are history,
not supported artifacts: if you are running one, you are running your own copy, and
nothing here covers it.

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
reset), the billing and subscription flows, and any exposure of one user's data to
another.

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

This repository is **private**, so GitHub renders this file only to people who
already have access to it. Anyone outside cannot read it, and GitHub's private
vulnerability reporting is not available on private repositories either.

This file therefore documents the process, but it is **not** by itself a way for an
outside researcher to find out how to reach us.

**The published disclosure path is the RFC 9116 file at
`https://nouriloop.com/.well-known/security.txt`** (added 2026-08-10), served by
`SecurityTxtController` and carrying the contact address plus the essential testing
rules inline — because a researcher who reaches it can read nothing else we have
written, including this file.

⚠️ **That route is the real policy as far as the outside world is concerned.** If it
is ever removed, or allowed to fall behind the firewall, or left to lapse past its
`Expires` date, NouriLoop is back to having no discoverable security contact no
matter what this document says. `SecurityTxtTest` guards all three.
