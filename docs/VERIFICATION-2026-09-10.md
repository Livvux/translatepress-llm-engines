# Verification of the cost-control upgrade — 2026-09-10

Baseline: `39d79b1069594b7c0398d75f7df4c25a4f63f640` (version 1.2.3).
The verification and fixes were developed on `fix/verify-cost-controls-20260910`.
Production and the main branch were not modified by this verification.

## Reproduced and fixed

1. **Expired failure history survived delayed housekeeping.** A state row with
   `failures=10`, an expired `expires_at` and elapsed retry time returned a new
   claim carrying all ten failures. Its next failure therefore resumed the high
   backoff even after retention elapsed. Claiming now resets expired history
   atomically before extending `expires_at`. Unexpired history remains intact.
2. **Correcting a saved model did not clear its old provider cooldown.** With the
   same API key, changing an unavailable model left `http-4xx` active and blocked
   the corrected request. A changed model now clears the cooldown; unchanged
   settings retain it and the unchanged credential's model catalogue stays cached.

Six real-database assertions cover these regressions. The integration suite now
also asserts hourly scheduling and exercises cleanup through the registered hook.
No new dependency, schema version, production configuration or release bump was needed.

## Verification results

- `php tests/run.php`: 99 passing tests.
- `php tests/reliability.php`: 147 passing checks.
- `npm ci --ignore-scripts --no-audit --no-fund` and `npm test`: 16 passing DOM tests;
  the committed lockfile remained unchanged.
- `wp eval-file tests/integration/run.php`: 84 passing assertions with actual
  WordPress 7.1, TranslatePress 3.3.5, PHP 8.3.33 and MariaDB 11.4.12.
  Eight independent WordPress workers issued one mocked provider request for a
  shared source; 400 concurrent ledger increments produced exactly 4.000000000000.
  Failure backoff, stale-owner fencing, rejected-row persistence, human-reviewed
  rows, delayed dictionary writes, quota, diagnostics and retention passed.
- PHP syntax checks and `git diff --check` passed.
- Disposable-site upgrade check: only the two plugin tables were created; the
  existing dictionary schema and reviewed translation were unchanged; activation
  state was unchanged; repeated initialization succeeded.
- A database account with SELECT/INSERT/UPDATE/DELETE but no CREATE permission
  returned `ensure() === false` and emitted the schema-error action. Existing
  integration coverage also verifies no provider call when state storage is absent.
- `wp cron event list` showed `trp_llm_housekeeping` with a one-hour recurrence;
  `wp cron event run trp_llm_housekeeping` completed successfully. The integration
  cleanup checks verify expired rows are removed while a live lease survives.
- Real OpenRouter, `google/gemini-2.5-flash-lite`: 32/32 translations passed across
  DE/FR/PT-BR/ES, including HTML, printf/mustache/TranslatePress tokens and Unicode.
  Four HTTP 200 responses ended with `stop`; repeated engine calls used the SQL
  handoff without another HTTP call. Reported cost: 0.000355212 USD across four
  ledger responses. The private key was injected only into the disposable CLI
  process and was not saved in the test site's settings or committed.
- Authenticated Chromium at 1440×1000 and 390×844: all four provider panels
  rendered; normal, empty, failed and overlapping model refreshes preserved the
  intended selection. Invalid nonce returned 403. No page errors were observed.
  Fault responses were browser fixtures; no production setting was saved.

A fresh private production backup completed before the database checks:
`20260910T093545Z-db`. Database tests ran on a separate Docker network and database,
with a loopback-only browser port, not against production tables. The fixture
setup was reset before the final integration run; importing a partial initial dump
alone is insufficient because it does not drop later-created dictionary tables.

## Limits

This verifies the stated paths, not mathematical absence of every possible bug.
The other direct providers have no live credentials in this environment; their
request/response behavior is covered by fixtures, not paid external certification.
The live provider check exercises the real engine and state/cost tables; the
renderer/dictionary concurrency tests use fixture responses. The test site is a
clean upstream WordPress installation, not a clone of all storefront integrations.
External glossary/HTTP-prompt changes still require a deterministic
`trp_llm_configuration_version`. A deployment must provide regular WP-Cron
execution. Timeouts and crashes can still result in ambiguous provider billing,
as documented in RELIABILITY.md.
