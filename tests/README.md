# Tests

Run from the repository root with PHP 8.1 or newer:

```sh
php tests/run.php
find . -type f -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
node --check assets/js/trp-llm-engines-settings.js
```

The suite has no Composer dependencies, database, WordPress installation or API-key requirement. It never makes provider requests. Failed checks and PHP warnings produce a nonzero exit status, independently of `zend.assertions`.

## Scope

The real `TRP_LLM_Response_Normalizer`, `TRP_LLM_Placeholder_Guard` and `TRP_LLM_Chunk_Runner` classes are tested. `bootstrap.php` explicitly doubles their WordPress, HTTP, response-metadata, budget, cooldown and quota collaborators. The tests exercise parser behavior and orchestration decisions, not the implementations of those doubles.

Coverage includes malformed and wrapped JSON, canonical numeric positions, exact repetition, Unicode whitespace, placeholder inventories, source-key preservation, truncation escalation and split limits, refusals, quota/cooldown/budget gates, and paid-response accounting calls.

GitHub Actions configures the suite and PHP lint for PHP 8.1–8.5. The 8.4 job also checks JavaScript syntax. A configured matrix is not evidence that those jobs have completed; check the PR's actual workflow results.

For a before/after check against separately checked-out production classes:

```sh
php tests/run.php /path/to/baseline/includes
```

The baseline may have additional extension requirements, such as its old repetition check's use of `mbstring`.

## Still required before deployment

Test on a staging WordPress installation with the deployed TranslatePress version. Confirm the logger's quota behavior, dictionary persistence, settings saves, supported provider/model request bodies, and actual response shapes. Run controlled translations through each configured provider. No live WordPress or paid-provider end-to-end result is implied by this unit suite.
