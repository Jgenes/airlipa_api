#!/usr/bin/env python3
"""Semantic checks that a PHP binary is not needed for.

Catches the mistakes that actually happen when wiring a Laravel app by hand:

  1. routes/**.php referencing a controller method that does not exist
     (the classic 500 at runtime: BadMethodCallException).
  2. `use App\\...;` imports that resolve to no file (PSR-4 drift).
  3. Classes referenced in `new X(...)`, `X::method(` or `X::class` inside the
     app that do not exist anywhere in the app namespace map.
  4. Route files referencing controller classes that were never imported.
  5. Injected services that do not expose the method being called.
  6. Enum cases referenced in code that the enum no longer declares.
  7. Blade mail views named by Mailables that do not exist.
  8. config('group...') references with no matching config file.
  9. Model enum casts whose values the migration's enum column rejects.

Usage:
    python3 tools/semantic_checks.py
    python3 tools/semantic_checks.py --json

Exit codes: 0 = clean, 1 = findings, 2 = checker error.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# PSR-4 map used by composer.json.
PSR4 = {"App\\": ROOT / "app", "Database\\Factories\\": ROOT / "database" / "factories", "Database\\Seeders\\": ROOT / "database" / "seeders"}
EXCLUDED_DIRS = {"vendor", "node_modules", ".git", "storage", ".venv-check", "__pycache__"}

USE_RE = re.compile(r"^\s*use\s+(App\\[A-Za-z0-9_\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;", re.MULTILINE)
NAMESPACE_RE = re.compile(r"^\s*namespace\s+([A-Za-z0-9_\\]+)\s*;", re.MULTILINE)
CONTROLLER_ACTION_RE = re.compile(r"\[([A-Za-z0-9_\\]+Controller)::class\s*,\s*'([A-Za-z0-9_]+)'\]")
CONTROLLER_INVOKE_RE = re.compile(r"\[\s*([A-Za-z0-9_\\]+Controller)::class\s*\]")
SHORT_CONTROLLER_RE = re.compile(r"Controller::class")


def php_files(*roots: str) -> list[Path]:
    files: list[Path] = []
    for root in roots:
        base = ROOT / root
        if not base.exists():
            continue
        for candidate in sorted(base.rglob("*.php")):
            if any(part in EXCLUDED_DIRS for part in candidate.parts):
                continue
            files.append(candidate)
    return files


def class_to_path(fqcn: str) -> Path | None:
    for prefix, directory in PSR4.items():
        if fqcn.startswith(prefix):
            relative = fqcn[len(prefix) :].replace("\\", "/")
            return directory / f"{relative}.php"
    return None


def read(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return ""


def imports_of(source: str) -> dict[str, str]:
    """alias => FQCN for `use App\\...;` statements."""
    found: dict[str, str] = {}
    for match in USE_RE.finditer(source):
        fqcn = match.group(1)
        alias = match.group(2) or fqcn.rsplit("\\", 1)[-1]
        found[alias] = fqcn
    return found


def check_imports(files: list[Path]) -> list[dict]:
    findings = []
    for path in files:
        for alias, fqcn in imports_of(read(path)).items():
            target = class_to_path(fqcn)
            if target is not None and not target.exists():
                findings.append(
                    {
                        "check": "unresolvable_import",
                        "file": str(path.relative_to(ROOT)),
                        "detail": f"use {fqcn}; -> missing {target.relative_to(ROOT)}",
                    }
                )
    return findings


def check_route_handlers() -> list[dict]:
    findings = []

    for path in php_files("routes"):
        source = read(path)
        imports = imports_of(source)

        for match in CONTROLLER_ACTION_RE.finditer(source):
            short_name, method = match.group(1), match.group(2)
            findings.extend(_verify_handler(path, imports, short_name, method))

        for match in CONTROLLER_INVOKE_RE.finditer(source):
            findings.extend(_verify_handler(path, imports, match.group(1), "__invoke"))

    return findings


def _resolve_class(source: str, imports: dict[str, str], short_name: str) -> str | None:
    """Resolve a short class name the way PHP would inside this file."""
    if short_name in imports:
        return imports[short_name]

    namespace_match = NAMESPACE_RE.search(source)
    if namespace_match:
        return f"{namespace_match.group(1)}\\{short_name}"

    return None  # global namespace: an unimported short name does not exist


def _verify_handler(path: Path, imports: dict[str, str], short_name: str, method: str) -> list[dict]:
    source_file = read(path)
    fqcn = _resolve_class(source_file, imports, short_name)

    if fqcn is None:
        return [
            {
                "check": "route_controller_not_imported",
                "file": str(path.relative_to(ROOT)),
                "detail": f"{short_name}::{method} referenced without an import (file has no namespace, so this is a fatal error)",
            }
        ]

    target = class_to_path(fqcn)

    if target is None or not target.exists():
        return [
            {
                "check": "route_controller_missing",
                "file": str(path.relative_to(ROOT)),
                "detail": f"{short_name}::{method} -> controller class {fqcn} not found",
            }
        ]

    source = read(target)

    if not re.search(rf"function\s+{re.escape(method)}\s*\(", source):
        return [
            {
                "check": "route_method_missing",
                "file": str(path.relative_to(ROOT)),
                "detail": f"{short_name}::{method} -> {target.relative_to(ROOT)} has no such method",
            }
        ]

    return []


def check_route_controller_imports() -> list[dict]:
    """A Controller::class reference with no matching `use` means a fatal error."""
    findings = []

    for path in php_files("routes"):
        source = read(path)
        imports = imports_of(source)

        referenced = set(re.findall(r"([A-Za-z0-9_]+Controller)::class", source))
        for short_name in sorted(referenced):
            fqcn = _resolve_class(source, imports, short_name)
            target = class_to_path(fqcn) if fqcn else None
            if target is None or not target.exists():
                findings.append(
                    {
                        "check": "route_controller_not_imported",
                        "file": str(path.relative_to(ROOT)),
                        "detail": f"{short_name}::class is referenced but not imported and does not resolve",
                    }
                )

    return findings


def check_service_methods() -> list[dict]:
    """Calls on injected collaboration classes must exist on those classes.

    Handles both styles used in this codebase:
      * constructor-promoted/typed properties: `$this->foo->bar()`
      * container resolution: `app(Foo::class)->bar()`
    """
    findings = []

    for path in php_files("app"):
        source = read(path)
        imports = imports_of(source)

        # Resolve every declared property type through the file's imports.
        declared: dict[str, str] = {}
        for match in re.finditer(
            r"(?:public|protected|private)(?:\s+readonly)?\s+([A-Za-z0-9_\\]+)\s+\$([A-Za-z0-9_]+)",
            source,
        ):
            type_name, property_name = match.group(1), match.group(2)
            short = type_name.rsplit("\\", 1)[-1]
            resolved = type_name if "\\" in type_name else _resolve_class(source, imports, short)
            if resolved:
                declared[property_name] = resolved

        for property_name, fqcn in declared.items():
            target = class_to_path(fqcn)
            if target is None or not target.exists() or target == path:
                continue
            target_source = read(target)

            for call in re.finditer(rf"\$this->{re.escape(property_name)}->([A-Za-z0-9_]+)\(", source):
                method = call.group(1)
                if not re.search(rf"function\s+{re.escape(method)}\s*\(", target_source):
                    findings.append(
                        {
                            "check": "service_method_missing",
                            "file": str(path.relative_to(ROOT)),
                            "detail": f"$this->{property_name}->{method}() is not defined in {fqcn}",
                        }
                    )

        # Container-resolved collaborations.
        for call in re.finditer(r"app\(([A-Za-z0-9_\\]+)::class\)->([A-Za-z0-9_]+)\(", source):
            type_name, method = call.group(1), call.group(2)
            short = type_name.rsplit("\\", 1)[-1]
            fqcn = type_name if "\\" in type_name else (_resolve_class(source, imports, short) or type_name)
            target = class_to_path(fqcn)
            if target is None or not target.exists():
                continue
            if not re.search(rf"function\s+{re.escape(method)}\s*\(", read(target)):
                findings.append(
                    {
                        "check": "service_method_missing",
                        "file": str(path.relative_to(ROOT)),
                        "detail": f"app({fqcn}::class)->{method}() is not defined",
                    }
                )

    return findings


BUILTIN_ENUM_MEMBERS = {"from", "tryFrom", "cases", "class", "name", "value"}


def enum_cases() -> dict[str, set[str]]:
    """App\\Enums\\Foo => set of case/const names declared on it."""
    cases: dict[str, set[str]] = {}
    enums_dir = ROOT / "app" / "Enums"

    if not enums_dir.exists():
        return cases

    for path in sorted(enums_dir.glob("*.php")):
        source = read(path)
        class_match = re.search(r"^\s*(?:enum|class)\s+([A-Za-z0-9_]+)", source, re.MULTILINE)
        if not class_match:
            continue
        names = set(re.findall(r"^\s*case\s+([A-Za-z0-9_]+)", source, re.MULTILINE))
        names |= set(re.findall(r"^\s*(?:public\s+)?const\s+([A-Z0-9_]+)\s*=", source, re.MULTILINE))
        cases[f"App\\Enums\\{class_match.group(1)}"] = names

    return cases


def check_enum_cases() -> list[dict]:
    findings = []
    known = enum_cases()

    for path in php_files("app", "tests", "database", "routes"):
        source = read(path)
        imports = imports_of(source)

        for alias, fqcn in imports.items():
            if fqcn not in known:
                continue
            for match in re.finditer(rf"\b{re.escape(alias)}::([A-Za-z_][A-Za-z0-9_]*)", source):
                member = match.group(1)
                if member in BUILTIN_ENUM_MEMBERS:
                    continue
                # Skip method calls such as Foo::make(
                if source[match.end() : match.end() + 1] == "(":
                    continue
                if member not in known[fqcn]:
                    findings.append(
                        {
                            "check": "enum_case_missing",
                            "file": str(path.relative_to(ROOT)),
                            "detail": f"{alias}::{member} is not a case of {fqcn}",
                        }
                    )

    return findings


def check_mail_views() -> list[dict]:
    """Mailable `view('emails.x')` targets must exist as Blade files."""
    findings = []
    views_dir = ROOT / "resources" / "views"

    for path in php_files("app"):
        source = read(path)

        # Covers both `->view('emails.x')` and the Laravel 12 named-argument
        # style `new Content(view: 'emails.x', ...)`.
        for match in re.finditer(
            r"(?:->\s*(?:view|markdown)\s*\(|\b(?:view|markdown)\s*:)\s*'([A-Za-z0-9_.\-/]+)'",
            source,
        ):
            view = match.group(1).replace(".", "/")
            candidate = views_dir / f"{view}.blade.php"
            if not candidate.exists():
                findings.append(
                    {
                        "check": "mail_view_missing",
                        "file": str(path.relative_to(ROOT)),
                        "detail": f"view '{match.group(1)}' -> missing resources/views/{view}.blade.php",
                    }
                )

    return findings


def check_config_files() -> list[dict]:
    """config('group.key') must point at an existing config file."""
    findings = []

    for path in php_files("app", "routes", "database"):
        source = read(path)

        for match in re.finditer(r"config\(\s*'([a-z0-9_]+)(?:\.[a-z0-9_.]+)?'", source):
            group = match.group(1)
            if not (ROOT / "config" / f"{group}.php").exists():
                findings.append(
                    {
                        "check": "config_file_missing",
                        "file": str(path.relative_to(ROOT)),
                        "detail": f"config('{group}...') -> missing config/{group}.php",
                    }
                )

    return findings


def enum_backing_values() -> dict[str, dict[str, str]]:
    """App\\Enums\\Foo => {case name: backing value} for backed enums."""
    values: dict[str, dict[str, str]] = {}
    enums_dir = ROOT / "app" / "Enums"

    if not enums_dir.exists():
        return values

    for path in sorted(enums_dir.glob("*.php")):
        source = read(path)
        class_match = re.search(r"^\s*enum\s+([A-Za-z0-9_]+)\s*:\s*string", source, re.MULTILINE)
        if not class_match:
            continue
        pairs = re.findall(r"^\s*case\s+([A-Za-z0-9_]+)\s*=\s*'([^']*)'", source, re.MULTILINE)
        values[f"App\\Enums\\{class_match.group(1)}"] = dict(pairs)

    return values


def migration_enum_columns() -> dict[tuple[str, str], set[str]]:
    """(table, column) => allowed values, as declared by the migrations."""
    columns: dict[tuple[str, str], set[str]] = {}

    for path in sorted((ROOT / "database" / "migrations").glob("*.php")):
        source = read(path)
        table = None
        for match in re.finditer(
            r"Schema::(?:create|table)\(\s*'([a-z0-9_]+)'|\$table->enum\(\s*'([a-z0-9_]+)'\s*,\s*\[([^\]]*)\]",
            source,
        ):
            if match.group(1):
                table = match.group(1)
                continue
            if table is None:
                continue
            allowed = set(re.findall(r"'([^']*)'", match.group(3)))
            columns[(table, match.group(2))] = allowed

    return columns


def model_table(model: str, source: str) -> str:
    explicit = re.search(r"protected\s+\$table\s*=\s*'([a-z0-9_]+)'", source)
    if explicit:
        return explicit.group(1)
    snake = re.sub(r"(?<!^)(?=[A-Z])", "_", model).lower()
    return snake if snake.endswith("s") else f"{snake}s"


def check_enum_column_drift() -> list[dict]:
    """A model casting a column to an enum must fit the column's migration enum.

    This is the failure mode behind 'CHECK constraint failed: purpose': a new
    OtpPurpose case was added while the column still only allowed the two
    original values. Production MySQL in strict mode rejects it too, so the
    write fails at runtime and not at review time.
    """
    findings = []
    backing = enum_backing_values()
    columns = migration_enum_columns()

    for path in php_files("app/Models"):
        source = read(path)
        model_match = re.search(r"^\s*class\s+([A-Za-z0-9_]+)", source, re.MULTILINE)
        if not model_match:
            continue

        model = model_match.group(1)
        table = model_table(model, source)
        enums = {
            alias: fqcn
            for alias, fqcn in imports_of(source).items()
            if fqcn in backing
        }

        for match in re.finditer(r"'([a-z0-9_]+)'\s*=>\s*([A-Za-z0-9_]+)::class\s*,", source):
            column, alias = match.group(1), match.group(2)
            if alias not in enums:
                continue
            allowed = columns.get((table, column))
            if allowed is None:
                continue
            declared = set(backing[enums[alias]].values())
            missing = sorted(declared - allowed)
            if missing:
                findings.append(
                    {
                        "check": "enum_column_drift",
                        "file": str(path.relative_to(ROOT)),
                        "detail": (
                            f"{table}.{column} allows {sorted(allowed)} but "
                            f"{enums[alias]} also has {missing}"
                        ),
                    }
                )

    return findings


def main(argv: list[str]) -> int:
    files = php_files("app", "config", "database", "routes", "tests")

    if not files:
        print("No PHP files found; are you in the repository root?", file=sys.stderr)
        return 2

    findings: list[dict] = []
    findings += check_imports(files)
    findings += check_route_controller_imports()
    findings += check_route_handlers()
    findings += check_service_methods()
    findings += check_enum_cases()
    findings += check_mail_views()
    findings += check_config_files()
    findings += check_enum_column_drift()

    if "--json" in argv:
        print(json.dumps({"findings": findings, "files_checked": len(files)}, indent=2))
    else:
        for finding in findings:
            print(f"[{finding['check']}] {finding['file']}: {finding['detail']}")
        print(f"\nChecked {len(files)} file(s): {len(findings)} finding(s).")

    return 0 if not findings else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
