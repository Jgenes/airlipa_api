#!/usr/bin/env bash
#
# One command to verify this repository as far as the machine allows.
#
#   bash tools/verify.sh            # full ladder
#   bash tools/verify.sh --fast     # skip the (slow) per-file PHP lint
#
# Tooling that is not on PATH can be pointed at explicitly:
#   PHP_BIN=/tmp/node_modules/.bin/php-wasm-cli \
#   PYTHON_BIN=/tmp/tsvenv/bin/python bash tools/verify.sh
#
# Layers, cheapest first:
#   1. PHP syntax   - `php -l` per file when PHP exists, else the tree-sitter
#                     PHP 8 grammar check (tools/php_syntax_check.py).
#   2. PHP logic    - framework-free money tests (tools/logic_tests.php):
#                     posting rules balance, QR/CRC correctness, tier maths.
#   3. Semantics    - route handlers, imports, enum cases, mail views, config
#                     groups, injected-service methods (tools/semantic_checks.py).
#   4. Test suite   - Pest, only when vendor/ exists (needs `composer install`).
#
# Exit code 0 means every layer that could run here passed.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

FAST=0
[ "${1:-}" = "--fast" ] && FAST=1

PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    PHP_BIN=""
fi

# Python interpreter that has the tree-sitter PHP grammar installed.
PYTHON_BIN="${PYTHON_BIN:-python3}"
if ! command -v "$PYTHON_BIN" >/dev/null 2>&1; then
    PYTHON_BIN=""
fi

status=0
files=$(find app config database routes tests bootstrap public -name '*.php' 2>/dev/null | sort)

section() { printf '\n=== %s ===\n' "$1"; }
ok()      { printf '  PASS  %s\n' "$1"; }
bad()     { printf '  FAIL  %s\n' "$1"; status=1; }
skip()    { printf '  SKIP  %s\n' "$1"; }

section "1/4 PHP syntax"

if [ -n "$PHP_BIN" ] && [ "$FAST" -eq 0 ]; then
    echo "  using $($PHP_BIN -r 'echo PHP_VERSION;' 2>/dev/null || echo "$PHP_BIN")"
    lint_failures=0
    linted=0
    while IFS= read -r file; do
        linted=$((linted + 1))
        output=$("$PHP_BIN" -l "$file" 2>&1)
        case "$output" in
            "No syntax errors"*) : ;;
            *) bad "$file"; printf '%s\n' "$output" | head -3 | sed 's/^/        /'; lint_failures=$((lint_failures + 1)) ;;
        esac
    done <<< "$files"
    [ "$lint_failures" -eq 0 ] && ok "php -l clean across ${linted} files"
elif [ -n "$PHP_BIN" ] && [ "$FAST" -eq 1 ]; then
    skip "per-file php -l (--fast); tree-sitter check follows"
fi

if [ -n "$PYTHON_BIN" ] && "$PYTHON_BIN" -c 'import tree_sitter_php' >/dev/null 2>&1; then
    if "$PYTHON_BIN" tools/php_syntax_check.py app config database routes tests bootstrap public | tail -1 | grep -q '0 with syntax errors'; then
        ok "tree-sitter PHP grammar parse clean"
    else
        bad "tree-sitter parse found syntax errors (run: python3 tools/php_syntax_check.py)"
    fi
else
    skip "tree-sitter check (pip install tree-sitter tree-sitter-php, or set PYTHON_BIN)"
fi

section "2/4 PHP logic (framework-free)"

if [ -n "$PHP_BIN" ]; then
    if "$PHP_BIN" tools/logic_tests.php; then
        ok "money machinery logic tests"
    else
        bad "logic tests failed"
    fi
else
    skip "logic tests need a PHP binary (php, or: npm i -g @php-wasm/cli and PHP_BIN=php-wasm-cli)"
fi

section "3/4 Semantic checks"

if [ -n "$PYTHON_BIN" ]; then
    if "$PYTHON_BIN" tools/semantic_checks.py | tee /tmp/verify-semantic.log | tail -1 | grep -q ' 0 finding(s)'; then
        ok "routes, imports, enum cases, mail views, config groups, service methods"
    else
        bad "semantic findings:"
        grep -v 'finding(s)' /tmp/verify-semantic.log | sed 's/^/        /' | head -20
    fi
else
    skip "semantic checks need python3 (set PYTHON_BIN)"
fi

section "4/4 Test suite (Pest)"

if [ -x vendor/bin/pest ]; then
    if "$PHP_BIN" vendor/bin/pest --colors=never; then
        ok "Pest suite"
    else
        bad "Pest suite"
    fi
else
    skip "vendor/ absent - run 'composer install' to execute the Pest suite"
fi

section "Result"
if [ "$status" -eq 0 ]; then
    echo "  All available layers passed."
else
    echo "  Verification failed - see the failures above."
fi

exit "$status"
