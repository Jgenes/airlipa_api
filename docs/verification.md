# Verification

> **Standing reminder carried over from the build phase**
> “No PHP runtime exists in this sandbox, so every phase was validated
> structurally and by review — CI will be the first real execution of the Pest
> suites.”

This file is that reminder, kept current. It records what can be proven in a
development sandbox, what only CI can prove, and the rule that follows from it:
**never describe the Pest suites as passing on the strength of a local run.**

## Where the reminder stands now

The premise has partly expired, and the part that still holds matters more than
ever.

| Claim in the reminder | Status today |
| --- | --- |
| “No PHP runtime exists in this sandbox” | **Partly superseded.** There is no system `php` and no Composer, but a real PHP 8.5 Zend CLI is installable from npm: `npm i @php-wasm/cli` → `node_modules/.bin/php-wasm-cli`. It runs `php -l` and standalone PHP scripts. |
| “Validated structurally and by review” | **Superseded for the things a parser can see.** Syntax, imports, route wiring, enum columns, mail views, config groups and the framework-free money maths are now executed, not eyeballed. |
| “CI will be the first real execution of the Pest suites” | **Happened — and it found real defects.** The first run was 50 failed / 81 passed. It is now green on PHP 8.2 and 8.3: 132 tests, ~930 assertions. |
| The underlying caution | **Still binding.** Artisan, migrations, Eloquent, the queue and the whole Pest suite need `vendor/`, and Packagist is unreachable from the sandbox. Anything about runtime behaviour that the static layers do not execute can only be settled by CI. |

That first execution is why the reminder is worth keeping: reading the code
carefully missed a duplicated `use Hash` (a Zend fatal on every payout),
`ProtectedPaymentService` being constructed with a null subledger (releases and
refunds silently moved no money), and OTP purposes the database rejected. None
of those are visible to review.

## What runs where

| Capability | Sandbox | CI |
| --- | --- | --- |
| `php -l` over every PHP file | yes (PHP 8.5 via WASM, ~145 s) | yes (PHP 8.2/8.3) |
| `tools/logic_tests.php` (10 money assertions) | yes | yes |
| `tools/php_syntax_check.py` (tree-sitter PHP 8) | yes (venv) | yes |
| `tools/semantic_checks.py` (9 checks) | yes | yes |
| `composer install` | **no** (Packagist blocked) | yes |
| `php artisan test` / Pest | **no** | yes, matrix 8.2 + 8.3 |
| Migrations against a real database | **no** | yes (SQLite in-memory) |

## Commands

Sandbox, full ladder (add `--fast` to skip the slow per-file lint):

```bash
npm i @php-wasm/cli                       # once, gives a real PHP CLI
python3 -m venv /tmp/tsvenv && /tmp/tsvenv/bin/pip install tree-sitter tree-sitter-php

PHP_BIN=$PWD/node_modules/.bin/php-wasm-cli \
PYTHON_BIN=/tmp/tsvenv/bin/python \
bash tools/verify.sh
```

Exit code 0 means every layer that *could* run passed. Layer 4 prints `SKIP`
when `vendor/` is absent — a skip is never reported as a pass.

CI: every push to any branch runs `.github/workflows/ci.yml`. Watch it with
`gh run watch` or `gh run list`. The `static` job fails in under a minute on a
broken import or route, before Composer is installed; the `tests` job runs the
Pest suite. When the suite fails, the job publishes the failing tests, their
assertion messages and the application log as a **commit comment**, so the
diagnosis does not depend on reaching the job log.

## Rules for future phases

1. **Run the ladder before saying a phase is done.** `bash tools/verify.sh`
   must exit 0, or the failures must be explained.
2. **Do not describe the suite as passing from a local run.** Quote the CI run
   that proves it. If CI has not run, say “unverified by execution”.
3. **A new check must be mutation-tested before it is trusted.** Break a scratch
   copy of the tree and confirm the check reports the break — every check in
   `tools/semantic_checks.py` was validated this way, and each has a known
   failure mode it was proven to catch.
4. **Missing coverage stays visible.** Layers that cannot run print `SKIP`; they
   never silently pass.
5. **CI is authoritative, not final.** It proves the suite runs and the ledger
   invariants hold under SQLite. It does not prove behaviour against the real
   Selcom API, under MySQL/Postgres row locking, or under load.
