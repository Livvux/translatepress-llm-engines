# Translation reliability and cost controls

This documents the follow-up to the translation-integrity audit. These changes do not select a different saved model, rotate credentials, rewrite existing translations, or publish a release.

## Upgrade and rollback

Test a database backup on staging before deployment. The WordPress database user must be allowed to create tables. The first operation needing shared state installs two tables through WordPress `dbDelta`, using the current site's table prefix:

- `trp_llm_state`: per-string owner leases, failure counters, retry deadlines and short-lived successful results.
- `trp_llm_costs`: UTC daily decimal cost totals and response counts, separated by provider and coverage.

The `trp_llm_schema_version` option tracks the schema. Installation also works on plugin upgrades without reactivation. Multisite tables are scoped to the current blog. TranslatePress dictionary tables are not altered. A storage failure leaves translations pending and emits `trp_llm_storage_error`; it does not silently send an unlocked request.

Deactivation clears this plugin's housekeeping event but intentionally retains the tables and settings. Rolling back the code therefore does not delete translations or operational records. Do not drop these tables while workers are translating. The old physical `trp_llm_cost_daily` option is retained for rollback, but becomes stale after upgrading; use the ledger for new totals.

## Per-string backoff and in-flight ownership

The identity is an HMAC of the source string, engine, model/request body, source and target locales, effective singleton prompts, configuration version and credential scope. No raw source text or API key is stored in the fingerprint. Batch membership and truncation attempts do not change the identity.

HTTP-success responses with unusable content increase only the affected strings' failure counters. Valid siblings can be saved. Transport failures, exhausted render budgets and locally rejected configurations do not create content-failure backoff. A successful retry resets the counter.

Default content backoff is 60 seconds, then 120, 240, and so on, capped at 24 hours, with up to 10% positive jitter. Failed state is retained for seven days. These values are controlled by:

| Filter | Default / purpose |
| --- | --- |
| `trp_llm_content_backoff_base` | 60 seconds |
| `trp_llm_content_backoff_max` | 86400 seconds |
| `trp_llm_content_backoff_seconds` | Final delay, receives the failure count |
| `trp_llm_state_retention_seconds` | 604800 seconds; never shorter than the retry delay |
| `trp_llm_lease_seconds` | 120 seconds; never shorter than the configured request timeout plus 30 seconds |
| `trp_llm_result_handoff_seconds` | 60 seconds, clamped to 1–300 seconds |

Claims use a unique database key and conditional SQL updates, not a transient or an object-cache get/set pair. Only the current random owner may renew, finish or release a lease. A stale worker cannot release a successor's lease or return an uncommitted translation to TranslatePress. Only a successful owner-fenced handoff write makes a translation eligible for dictionary persistence; expired/reclaimed leases and failed state writes leave it pending. Each outgoing HTTP attempt renews the required leases, including transport retries and split chunks. Cleanup runs in `finally`, including exceptions while acquiring later strings.

A successful translation is temporarily cached to cover the interval between the engine returning and TranslatePress committing its dictionary row. It can be reused during that interval without another provider request. The result is plaintext in the plugin's state table while retained. It expires logically after the handoff interval and is physically removed by housekeeping later; it is not a permanent translation cache.

These controls do not promise exactly-once provider billing. A crashed worker, an expired lease after a prolonged process pause, a timeout after the provider accepted a request, or transport-level retries can still cause ambiguous paid work. The TranslatePress character quota is also not a global atomic dollar-spending limit.

### Custom prompts and external configuration

Prompt and body filters must be deterministic for the same context. The fingerprint evaluates them on one source string to remain independent of incidental batching. Sites that alter requests through WordPress HTTP filters, use batch-dependent prompts, change excluded-term mappings, or update an external glossary must explicitly version that configuration:

```php
add_filter( 'trp_llm_configuration_version', static function () {
    return 'site-glossary-2026-09-10-v2';
} );
```

Changing this value makes previous backoff and handoff entries inapplicable; old entries expire normally. The existing `trp_llm_system_prompt`, `trp_llm_user_prompt`, `trp_llm_request_body`, output-token, chunk-size and OpenRouter fallback filters remain available.

## Model compatibility and prices

OpenAI discovery is intersected with an explicit capability registry. A model identifier appearing in `/v1/models` alone is not sufficient. The registry controls the output-token parameter, sampling-temperature support and maximum output size. Recognized GPT-5 entries use `max_completion_tokens` and omit `temperature`; supported legacy entries use their documented parameters. The body is validated again after the public body filter.

Unknown saved OpenAI models remain selected and produce a local configuration error. They are never silently replaced with another model. After verifying a model's Chat Completions contract, a site may add an exact identifier through `trp_llm_openai_capabilities`:

```php
add_filter( 'trp_llm_openai_capabilities', static function ( $registry ) {
    // Replace this identifier and values only after checking the provider contract.
    $registry['verified-exact-model-id'] = array(
        'token_parameter' => 'max_completion_tokens',
        'temperature' => false,
        'max_output_tokens' => 8192,
    );
    return $registry;
} );
```

Prices are a separate exact-ID registry, including explicitly listed dated snapshots. There is no broad prefix fallback: `gpt-4.1` cannot inherit `gpt-4` pricing, and a future suffix cannot inherit a current snapshot's price. Unverified prices display **Price unknown**. OpenRouter uses its live numeric price metadata; missing or invalid values are unknown, not free. Only explicit zero input and output prices display Free.

`trp_llm_model_prices` accepts provider → exact model ID → `[input, output]` prices in USD per million standard text tokens. Displayed list prices are not invoices or usage estimates; caching, reasoning, service tiers and provider changes can affect actual charges. The registry is deliberately a verified subset, not a claim to include every currently available model.

## Race-safe settings refresh

Each provider has an independent request sequence and current selection. New requests and API-key edits invalidate older callbacks before aborting the old request. Stale responses cannot replace options or clear the active request's busy state.

The real options remain visible and submittable while loading. Empty, malformed and failed responses preserve the current catalogue and selection, including a model selected while the request was running. A selected model missing from a successful catalogue is retained as a saved option. Completion does not enable a field that TranslatePress disabled. Model labels are inserted as text, not HTML.

## Retry-After and Anthropic responses

Long numeric or HTTP-date `Retry-After` values are not clamped into short inline sleeps. The response instead starts an engine cooldown. The provider-requested duration is a minimum, including for HTTP 503 and waits longer than the previous one-hour rate-limit cap. Ordinary short retries remain bounded by the render budget.

Anthropic responses are read as typed content blocks. Text blocks are concatenated in order; thinking and redacted-thinking blocks are ignored. Tool, unknown and malformed blocks are not accepted as translations. Token and context-window truncation still use the bounded escalation sequence.

## Cost ledger and coverage

Each HTTP-success response adds one atomic SQL increment, even when its content is rejected. Decimal addition happens in the database, not in a shared serialized-option read/modify/write cycle.

| Coverage | Meaning |
| --- | --- |
| `reported` | The response contained a finite nonnegative `usage.cost`, including explicit zero. |
| `unknown` | The response did not contain a usable cost. Its response count is recorded, but zero in the storage amount is not a zero-cost claim. |
| `legacy` | Imported approximate historical option totals; there is no historical response count. |

OpenRouter is configured to include usage and may report cost. OpenAI, Anthropic and DeepSeek responses without a cost amount remain unknown; prices are not used to invent invoice totals. A timeout with no received successful response also cannot be reconciled to the provider's bill here.

The old daily option is imported idempotently with `INSERT IGNORE`. Its public option read becomes a compatibility view of legacy and reported amounts. For complete coverage, use the structured ledger instead:

```sh
wp eval 'echo wp_json_encode(TRP_LLM_Cost_Ledger::daily(), JSON_PRETTY_PRINT);'
```

Rows use UTC dates. `trp_llm_cost_retention_days` defaults to 90 and is clamped to 1–3650 days. Ledger totals are operational diagnostics, not a replacement for provider billing records.

## Diagnostics and retention

Diagnostic snippets are redacted by default. The plugin keeps capped metadata rings for HTTP failures, content failures and placeholder rejections. Retention defaults to seven days. Unknown fields are not persisted. Existing snippets are cleaned on the first scheduling pass after upgrade and during hourly housekeeping.

| Filter | Default |
| --- | --- |
| `trp_llm_diagnostics_enabled` | `true`; disabling removes entries during pruning and prevents new content |
| `trp_llm_diagnostic_content` | `false`; opt in only for a controlled debugging window |
| `trp_llm_diagnostic_retention_seconds` | 604800; zero removes entries during pruning |
| `trp_llm_redact_diagnostic` | Optional additional site-specific redaction |

Opted-in samples are clipped and scrubbed for saved API keys, common bearer/key patterns, email addresses and sensitive URL query parameters. This is defense in depth, not complete anonymization of arbitrary confidential content. The vendor request log receives flat redacted strings and HTTP/finish metadata rather than raw response objects or headers. Its character-count column consequently describes the logged redacted strings, not billable source characters; quota accounting continues to use the real chunk.

TranslatePress owns its own existing request-log table, page URLs and retention. This plugin does not erase old vendor logs. API-key storage and password-field behavior are unchanged by this work.

Housekeeping uses `trp_llm_housekeeping`, scheduled hourly. On sites with disabled traffic-driven WP-Cron, arrange regular system-cron execution of due WordPress events. Expired entries stop influencing translation immediately, but physical cleanup requires the event to run. Manual housekeeping:

```sh
wp cron event run trp_llm_housekeeping
```

## Verification scope

`php tests/run.php` covers the existing parser, placeholder guard and chunk-runner contracts with collaborators doubled. `php tests/reliability.php` exercises the actual capability, pricing, fingerprint, retry, typed-content and redaction policy classes with WordPress/HTTP I/O doubles. `npm ci --ignore-scripts` installs the committed test dependency lockfile. `npm test` runs the settings script against real jQuery in a jsdom DOM.

The integration workflow provisions disposable WordPress 7.1, TranslatePress 3.3.5 and MariaDB 10.11. Its tests use the actual plugin lifecycle, renderer, query API, dictionary persistence and vendor logger. Eight separate WordPress processes wait at a readiness barrier before contending for the same translation or incrementing the shared cost ledger. Additional renderer-level cases verify that expired/reclaimed leases and a failed handoff write cannot persist a stale result, while the received provider response is still counted. Only provider HTTP is intercepted by a test-only fixture; there are no real API keys or paid provider calls. A defined test suite or configured workflow is not evidence of passing execution; consult the checks on the exact PR commit.

The fixture is guarded by both `WP_CLI` and `TRP_LLM_INTEGRATION_TESTS`. Never install it on a production site. These tests do not establish support for every WordPress/TranslatePress version or real-world translation quality.

## Primary references

Registry review date: 2026-09-10. Exact identifiers and their contracts should be rechecked when extending the registry.

- [OpenAI Chat Completions reference](https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create)
- [OpenAI standard API pricing](https://developers.openai.com/api/docs/pricing)
- [OpenAI model catalogue](https://developers.openai.com/api/docs/models)
- [GPT-5 developer parameters and pricing](https://openai.com/index/introducing-gpt-5-for-developers/)
- [Anthropic pricing](https://platform.claude.com/docs/en/about-claude/pricing)
- [Anthropic stop reasons](https://platform.claude.com/docs/en/build-with-claude/handling-stop-reasons)
- [WordPress dbDelta](https://developer.wordpress.org/reference/functions/dbdelta/)
- [jQuery AJAX callback behavior](https://api.jquery.com/jQuery.ajax/)
