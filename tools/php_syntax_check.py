#!/usr/bin/env python3
"""Parse-level PHP verification using the real PHP 8 grammar.

This is the strongest check available without a PHP binary: every .php file is
parsed with tree-sitter-php (the same grammar editors use) and any ERROR or
MISSING node is reported with file, line, column and a code excerpt.

Usage:
    python3 tools/php_syntax_check.py [paths...]

Exit codes:
    0 = every file parsed cleanly
    1 = at least one syntax error
    2 = the checker itself could not run (missing dependency)

Install the parser once:
    python3 -m venv .venv-check
    .venv-check/bin/pip install tree-sitter tree-sitter-php
"""

from __future__ import annotations

import sys
from pathlib import Path

try:
    import tree_sitter
    import tree_sitter_php
except ModuleNotFoundError:  # pragma: no cover
    print(
        "tree-sitter is not installed.\n"
        "  python3 -m venv .venv-check\n"
        "  .venv-check/bin/pip install tree-sitter tree-sitter-php",
        file=sys.stderr,
    )
    raise SystemExit(2)

DEFAULT_PATHS = ["app", "config", "database", "routes", "tests", "bootstrap", "public"]
EXCLUDED_DIRS = {"vendor", "node_modules", ".git", "storage", ".venv-check", "__pycache__"}

LANGUAGE = tree_sitter.Language(tree_sitter_php.language_php())
PARSER = tree_sitter.Parser(LANGUAGE)


def php_files(roots: list[str]) -> list[Path]:
    files: list[Path] = []

    for root in roots:
        path = Path(root)
        if path.is_file() and path.suffix == ".php":
            files.append(path)
            continue
        if not path.exists():
            continue
        for candidate in sorted(path.rglob("*.php")):
            if any(part in EXCLUDED_DIRS for part in candidate.parts):
                continue
            files.append(candidate)

    return files


def walk(node):
    """Yield every node, depth-first."""
    stack = [node]
    while stack:
        current = stack.pop()
        yield current
        stack.extend(reversed(current.children))


def check(path: Path) -> list[dict]:
    source = path.read_bytes()
    tree = PARSER.parse(source)
    root = tree.root_node
    problems: list[dict] = []

    for node in walk(root):
        if node.type == "ERROR" or node.is_missing:
            problems.append(
                {
                    "kind": "MISSING" if node.is_missing else "ERROR",
                    "line": node.start_point[0] + 1,
                    "column": node.start_point[1] + 1,
                    "byte_range": (node.start_byte, node.end_byte),
                    "excerpt": source[node.start_byte : min(node.end_byte, node.start_byte + 120)]
                    .decode("utf-8", "replace")
                    .replace("\n", "\\n"),
                }
            )

    # A non-empty file whose root has no php/html node is suspicious too.
    if root.child_count == 0 and source.strip():
        problems.append({"kind": "EMPTY_TREE", "line": 1, "column": 1, "byte_range": (0, 0), "excerpt": ""})

    return problems


def main(argv: list[str]) -> int:
    roots = argv[1:] or DEFAULT_PATHS
    files = php_files(roots)

    if not files:
        print("No PHP files found.", file=sys.stderr)
        return 1

    failures = 0

    for path in files:
        problems = check(path)

        if problems:
            failures += 1
            print(f"\n{path}")
            for problem in problems[:10]:
                print(f"  {problem['kind']} at line {problem['line']}:{problem['column']}")
                if problem["excerpt"]:
                    print(f"    {problem['excerpt']}")

    print(f"\nChecked {len(files)} PHP file(s): {failures} with syntax errors.")

    return 0 if failures == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
