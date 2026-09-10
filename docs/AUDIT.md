# Translation reliability audit — 2026-09-10

Baseline: [`803116b12424597edf8101e16439ac7ba18e3d64`](https://github.com/Livvux/translatepress-llm-engines/commit/803116b12424597edf8101e16439ac7ba18e3d64), plugin version 1.2.2.

Scope: code review of the shared translation pipeline, provider request builders, OpenAI model discovery, settings/AJAX flow, and diagnostic/cost handling. This was not a live-site penetration test or a provider/model certification. Production anecdotes in existing comments were not independently verified.

## Fixed in this PR

| Priority | Finding | Change |
| --- | --- | --- |
| High | The runner returned matching-count translations before checking `length` / `max_tokens`. A valid JSON array can still be an unfinished response. Responses with no text bypassed escalation entirely. | Inspect truncation before accepting parsed content or returning `no-content`; keep the existing bounded escalation ladder. |
| High | Blank answers passed the placeholder guard whenever the source had no placeholders, allowing visible text to disappear. | Reject empty/whitespace-only answers for nonblank sources, including Unicode whitespace. Preserve blank layout sources and safe siblings. |
| High | `near_duplicate()` accepted prefix matches and kept the first element, even when the first element was shorter. Case/whitespace normalization also hid meaningful differences. | Recover only exact nonempty string repeats. Similar or partial outputs remain untranslated. |
| High | Arbitrary numeric object keys were sorted and discarded. A gap or nonstandard offset could silently misalign translations with source keys. | Accept only contiguous canonical zero- or one-based positions, including shuffled objects. Reject gaps, negatives, offsets and leading-zero aliases. |
| High | Quota and cooldown checks in the outer engine loop could not stop recursive truncation retries or the second split half. | Recheck the existing quota and cooldown at every runner attempt, alongside the existing render-budget gate. Preserve already recovered siblings. |
| Medium | Refusal/filter/tool-handoff metadata was ignored when message text happened to parse as translations. | Reject known non-translation stop reasons and explicit refusal metadata without an inline retry. Keep accounting for the HTTP-success response. |
| Medium | The surplus-bracket repair discarded everything after an unmatched closer, including another value or explanatory prose. | Only remove a tail consisting exclusively of surplus closers and whitespace. |
| Medium | No reproducible test suite or CI workflow was committed on the audited branch. | Add 99 dependency-free unit tests and a PHP 8.1–8.5 lint/test matrix with pinned action commits and read-only workflow permissions. |

The first five findings are grounded in `class-chunk-runner.php::attempt()`, `class-placeholder-guard.php::is_safe()`, and `class-response-normalizer.php::{trim_repetition,unwrap}`. The repair finding is in `trim_unbalanced_brackets()`.

Provider completion/refusal metadata was checked against the [OpenAI Chat Completions reference](https://developers.openai.com/api/reference/resources/chat) and [Anthropic stop-reason documentation](https://platform.claude.com/docs/en/build-with-claude/handling-stop-reasons).

### Behavior and compatibility

No credentials, saved models, default models, prompts, public filter names, database schema or existing translations are changed. No migration or release-version bump is included.

The parser is intentionally more conservative. Some previously recovered malformed responses will now remain pending instead of being stored with uncertain meaning or alignment. Provider-reported truncation may cause an additional bounded request where a matching array was previously accepted immediately. Existing quota and budget checks still apply; they are not a new atomic, cross-worker spending guarantee.

The tests exercise the three changed production classes with explicit collaborator doubles. They do not prove WordPress/TranslatePress integration or live provider compatibility. See [the testing guide](../tests/README.md).

## Remaining work, in priority order

### 1. Bound recurring content failures and duplicate in-flight work — High

`TRP_LLM_Chunk_Runner::attempt()` starts engine cooldowns for transport failures but not repeated malformed/refused/placeholder-invalid HTTP-success responses. Such strings remain eligible on later renders, so an unchanged bad input/model pair can be billed repeatedly. The stricter rejection rules in this PR make a bounded retry policy especially important.

Add a short-lived per-string failure cache keyed by provider, model, source/target locales, source hash and prompt/configuration revision. Avoid an engine-wide cooldown for one bad string. Reset failures on relevant configuration changes, and expose skipped/retryable counts. Add an expiring, atomic in-flight lock so concurrent renders do not request the same pending translation simultaneously. Test expiry and worker-failure recovery.

### 2. Align model discovery, request capabilities and pricing — High

`TRP_OpenAI_Machine_Translator::get_available_models()` admits broad `gpt-` / `o1` prefixes, while `send_request()` always sends the same `temperature` and `max_tokens` fields. The preferred-model list only sorts entries; it does not exclude incompatible ones. The discovery loop therefore does not establish that an offered model works with this adapter.

Create a tested capability registry per provider/model family, or conservatively expose only verified text-chat models while retaining explicit custom-model support with a warning. Validate token-limit and optional sampling fields against the chosen model's documented API. Do not silently switch a saved model.

`get_price_for_model()` also uses first-prefix matching: a model ID beginning `gpt-4.1` matches the generic `gpt-4` price row. Use exact or explicitly versioned-family matches, date the source, and show unknown pricing as unknown rather than attributing a nearby model's price.

### 3. Make model refresh preserve selection under races and failures — High

In `assets/js/trp-llm-engines-settings.js::fetchModels()`, overlapping blur/refresh requests can finish out of order. A second request can capture the temporary loading option's empty value. A successful empty catalogue leaves an empty option, and `restoreDefaultModels()` cannot restore a newly selected remote model absent from the original server-rendered HTML.

Track requests per provider, abort or ignore stale responses, preserve the last real selection independently of the loading DOM, and append that selection on all empty/error paths. Add DOM tests for rapid key edits, reversed response order, empty catalogues, and a new remote selection followed by refresh failure.

### 4. Honor long Retry-After values without blocking page rendering — Medium

`TRP_LLM_Request_Retry::send()` clamps the provider's wait to the configured short backoff and then retries. A 503 asking for a much longer wait can therefore be retried too early. The retryable engine cooldown uses a fixed duration rather than the provider's requested delay. The 429 cooldown is also capped.

When the requested wait cannot fit the render/backoff budget, do not retry inline. Carry an appropriate not-before time into the cooldown; make any protective maximum explicit. Test delta-seconds, HTTP dates, invalid values, and the interaction with the render deadline.

### 5. Make spend accounting and diagnostic retention trustworthy — Medium

`TRP_LLM_Request_Shape::flush_cost()` performs a read/modify/write on one option. Batching writes reduces frequency but does not prevent concurrent workers from losing increments. It also records only responses containing numeric `usage.cost`, so absence of a cost entry is not proof of zero spend.

Use atomic increments in a purpose-built table or an equivalent tested atomic store, and label provider coverage explicitly. Diagnostic excerpts can contain source text, returned text or provider error details (`TRP_LLM_Http_Failure_Log::detail()` and placeholder/chunk logs). Add redaction, configurable retention and a clear-data control; avoid making diagnostics public.

### 6. Complete provider response and staging integration coverage — Medium

`TRP_LLM_Request_Shape::message_content()` reads only the first Anthropic content block. A response with a leading non-text block or multiple text blocks may lose usable text. Parse typed text blocks without treating reasoning/tool content as translations, and cover this with provider fixtures.

Add staging tests against the actual TranslatePress logger and dictionary persistence, plus settings nonce/capability enforcement and supported WordPress/PHP combinations. Consider explicit HTML/attribute integrity checks after establishing what the deployed TranslatePress renderer already sanitizes; do not apply blanket HTML stripping to source snippets. This audit did not establish an exploitable XSS path.

## Release checks

Before merging, review the actual CI results. Before deployment, run a small staging translation set containing ordinary prose, HTML, non-Latin text, printf/mustache/TranslatePress tokens and long strings. Confirm rejected entries remain retryable and existing translations are unchanged. Monitor content-failure and truncation counts after rollout, especially until per-string backoff is implemented.
