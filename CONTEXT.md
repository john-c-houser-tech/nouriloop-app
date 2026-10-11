# NouriLoop

A multi-user nutrition tracker. This glossary covers the AI-analysis area so far; other areas get terms as they are worked on.

## Analysis

**Analysis**:
One Claude call that estimates nutrition (or reads a label) from photos or a description. Meal logs, clarify rounds, re-analyses, recipe imports and supplement scans are all analyses.
_Avoid_: scan (except for supplements), AI call, request

**Re-analysis**:
An analysis that overwrites an existing meal's numbers instead of creating a new meal.
_Avoid_: reanalyze-as-edit, refresh

**Clarify round**:
A follow-up analysis of the same meal or recipe draft after the user answers questions or corrects ingredients. Capped per draft.
_Avoid_: re-analysis round (that is a different thing; see Re-analysis), refine call, retry

**Supplement scan**:
An analysis that reads a supplement's label photos into per-dose nutrient amounts.
_Avoid_: supplement analysis, label read

**Draft**:
An analysis result the user is reviewing before anything is saved: a meal being logged, a recipe being imported, or a supplement being scanned. Clarify rounds refine it; saving turns it into a new record, discarding drops it.
_Avoid_: pending analysis, review state, session state

**Re-analysis proposal**:
A re-analysis result waiting for the user to apply it to the one meal it was made for. Applying overwrites that meal; it is never a new record, which is why it is not a draft.
_Avoid_: draft (for this), pending re-analysis

**Staged photo**:
An uploaded photo held for a draft or re-analysis proposal, not yet part of any saved record. Abandoned staged photos are swept.
_Avoid_: temp upload, parked file

## Allowance

**Allowance**:
A user's monthly budget of analyses; free tier gets a fixed number, entitled tiers are unlimited. Every analysis is checked against it before the call.
_Avoid_: quota, photo limit, credits

**Derived count**:
The part of the allowance spent, counted from what analyses left behind (saved meals, imported recipes) rather than from the calls themselves. Deleting the artefact refunds it, deliberately.
_Avoid_: usage counter

**Ledger**:
The record of analyses that leave nothing countable behind, so are booked when the call succeeds: re-analyses and supplement scans. Each entry is an analysis event and is never refunded.
_Avoid_: usage log, event log

**Ledgered analysis**:
An analysis whose spend is booked in the ledger rather than derived. Only re-analyses and supplement scans are ledgered.
_Avoid_: tracked analysis

## Health data

**Reading**:
One measurement as a source reports it (type, value in the source's own unit, when, and from which source), before it is stored.
_Avoid_: record, sample, data point, row

**Health metric**:
A stored, canonical reading: one per user, type and moment, in the app's canonical unit.
_Avoid_: measurement, health record

**Source**:
The app or device a reading came from (Health Auto Export, the Health Export app, an Apple Health export, a CSV file, an API client).
_Avoid_: provider, origin, device (unless it really is the device)

## Intake

**Daily intake**:
What a user took in on one local day: the meals logged that day plus the daily amounts of their active supplements. A day with no meals logged has no intake; supplements never count on their own.
_Avoid_: daily nutrition, daily totals (unqualified), consumption

**Active supplement**:
A supplement the user currently takes. It is a standing daily amount, not a logged event: while active it counts toward every day's intake that has a meal, from the day it was added on, never before. Switching one off removes it from every day, past ones too, because supplements keep no stop history.
_Avoid_: current supplement, supplement log

## Messages

**Channel**:
Where a scheduled message reaches the user: email, SMS (text), or in-app (the notification bell).
_Avoid_: medium, transport (that's the mail/SMS plumbing)

**Digest**:
The evening round-up of due notices (nothing logged today, stale health data, calories out of range, nutrient gaps), sent once a day at the user's local evening.
_Avoid_: daily email, notification email

**Check-in**:
A goal's scheduled progress report: the evaluated result of one goal over its window, delivered to the user.
_Avoid_: goal report, evaluation

**Reachable**:
Whether a user may be sent a given purpose's scheduled message on a given channel: their channel choice, consent (SMS), a verified address (email), a phone number, and the entitlement the purpose needs. Sign-in, reset and verification mail are not scheduled messages and don't follow it.
_Avoid_: deliverable, eligible
