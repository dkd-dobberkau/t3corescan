# t3corescan — TYPO3 Extension Scanner CLI

A small TYPO3 extension that exposes the **Core Extension Scanner** from
`EXT:install` as a Symfony Console command. Intended for plugging into
upgrade pipelines, score loops, and CI checks.

```bash
vendor/bin/typo3 t3x:extensionscanner:scan packages/ --format=json
```

The command uses the same matchers and configuration files as the
backend module under **Admin Tools → Upgrade → Scan Extension Files**.
Results are therefore identical to what an integrator would see in the
backend.

## Why this extension exists

The Extension Scanner ships in the TYPO3 Core as a backend module only.
There is no official `extensionscanner:scan` CLI command. If you need
the scan to be scriptable, you have two options: the established
third-party package `michielroos/typo3scan`, or a small command that
talks to the Core's own scanner directly. This repo is the second
option, deliberately bound to the installed Core version so the match
list always tracks the Core that's actually installed.

## ⚠ Deliberate use of `@internal` Core classes

Every class under
`typo3/sysext/install/Classes/ExtensionScanner/Php/` is marked
`@internal` in the Core. Their signatures may change between major
Core releases without a deprecation path. This is a deliberate
trade-off — it's the price of reusing the Core's own match
definitions.

All references to these internal classes are concentrated in **one**
file: [`Classes/Scanner/ScannerAdapter.php`](Classes/Scanner/ScannerAdapter.php).
When a Core update breaks the contract, that's the single place to
touch.

The matcher registry in the adapter mirrors
`vendor/typo3/cms-install/Classes/Controller/UpgradeController.php`
from the **TYPO3 v13.4** line. Verified against TYPO3 **v13.4.30**
(2026-05-27).

The functional test
[`Tests/Functional/Scanner/ScannerAdapterTest.php`](Tests/Functional/Scanner/ScannerAdapterTest.php)
is the early-warning signal: it asserts a known match against a
fixture and fails loudly as soon as the Core changes the internal
contract.

## Limits (by design)

- **Static analysis only.** Dynamically composed calls or class names
  that emerge only at runtime are out of scope. This note also appears
  verbatim in every report output.
- **Custom extensions only**, not Core extensions — same as the
  backend module.
- **No auto-fix.** The command reports, nothing else. Rewriting is
  what Rector / Fractor are for.

## Installation

In the `composer.json` of your TYPO3 13.4 project:

```json
{
  "repositories": [
    { "type": "path", "url": "../t3-corescan" }
  ],
  "require": {
    "t3x/t3corescan": "@dev"
  }
}
```

Then:

```bash
composer require t3x/t3corescan
vendor/bin/typo3 t3x:extensionscanner:scan
```

## Usage

```bash
# Default: auto-detect packages/ and typo3conf/ext/ under the project root
vendor/bin/typo3 t3x:extensionscanner:scan

# Explicit paths, JSON for downstream processing
vendor/bin/typo3 t3x:extensionscanner:scan packages/my_ext packages/other_ext --format=json

# Report hits but still exit zero
vendor/bin/typo3 t3x:extensionscanner:scan --no-fail

# Override directory excludes (default: vendor, node_modules, .Build, var, .git)
vendor/bin/typo3 t3x:extensionscanner:scan packages/ --exclude=vendor --exclude=Tests
```

### Options

| Option | Default | Meaning |
|---|---|---|
| `paths` (argument, variadic) | auto-detect | Files or directories to scan. |
| `--format` | `table` | `table` (human-readable) or `json` (machine-readable). |
| `--exclude` | see default | Directory names to skip. |
| `--no-fail` | off | Always exit zero, even when hits exist. |

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Clean run with no hits, or `--no-fail` was set. |
| `1` | Hits found. |
| `2` | Invalid invocation (non-existent path, unknown format). |

### JSON schema

```json
{
  "summary": {
    "filesScanned": 42,
    "filesWithHits": 3,
    "filesIgnored": 0,
    "filesWithParseErrors": 0,
    "hits": {
      "total": 5,
      "byIndicator": { "strong": 3, "weak": 2 }
    },
    "scannedPaths": ["packages/my_ext"],
    "projectRoot": "/var/www/html",
    "note": "Static analysis only — dynamically composed calls or runtime class names are out of scope."
  },
  "results": [
    {
      "file": "packages/my_ext/Classes/Foo.php",
      "absoluteFile": "/var/www/html/packages/my_ext/Classes/Foo.php",
      "isFileIgnored": false,
      "effectiveCodeLines": 84,
      "ignoredLines": 0,
      "parseError": null,
      "hits": [
        {
          "file": "packages/my_ext/Classes/Foo.php",
          "absoluteFile": "/var/www/html/packages/my_ext/Classes/Foo.php",
          "line": 12,
          "indicator": "strong",
          "matcher": "ClassNameMatcher",
          "message": "Usage of class \"TYPO3\\CMS\\Core\\Cache\\CacheFactory\"",
          "restFiles": ["Breaking-80700-DeprecatedFunctionalityRemoved.rst"]
        }
      ]
    }
  ]
}
```

Each hit contains:

- `file` / `absoluteFile` — project-relative and absolute path
- `line` — line number
- `indicator` — `strong` or `weak` (Core's own classification)
- `matcher` — short name of the matcher class (e.g. `ClassNameMatcher`)
- `message` — description from the Core; contains the fully qualified
  identifier verbatim, exactly the way the backend module shows it
- `restFiles` — references to the matching reST changelog files

`results` lists only files that have hits or a parse error. The
counters in `summary` cover all scanned files.

### Ignore annotations

The annotations used by the Core's backend module are honored:

- `@extensionScannerIgnoreFile` (on a class) → the entire file is
  skipped.
- `@extensionScannerIgnoreLine` (in a comment above a line) → the
  single line is skipped.

## Development & tests

Prerequisite: DDEV. The functional tests boot a complete TYPO3 13.4
instance via `typo3/testing-framework` and run on SQLite — no
dedicated database setup needed.

```bash
ddev start
ddev composer install
ddev test
```

On the first `ddev start` the `post-start` hook runs `composer install`
automatically. The repeated `ddev composer install` is harmless and
only needed if the hook failed.

### What the tests check

- [`Tests/Functional/Scanner/ScannerAdapterTest.php`](Tests/Functional/Scanner/ScannerAdapterTest.php)
  scans
  [`Tests/Fixtures/DeprecatedClassUsage.php`](Tests/Fixtures/DeprecatedClassUsage.php)
  (uses the removed class `TYPO3\CMS\Core\Cache\CacheFactory`) and
  expects **exactly one** `ClassNameMatcher` hit with
  `indicator=strong` plus the reST reference
  `Breaking-80700-DeprecatedFunctionalityRemoved.rst`. A clean fixture
  must produce no hits at all.
- [`Tests/Functional/Command/ScanCommandTest.php`](Tests/Functional/Command/ScanCommandTest.php)
  drives the command through
  `Symfony\Component\Console\Tester\CommandTester`, validates the
  JSON schema, and checks exit-code behavior (`1` on hits, `0` with
  `--no-fail`).

When a Core update breaks the internal contract, these tests are the
first thing to fail.

### Verified state

| | |
|---|---|
| TYPO3 Core | v13.4.30 |
| PHP | 8.2.30 |
| testing-framework | 9.5.0 |
| PHPUnit | 11.5.55 |
| Verified | 2026-05-27 |
| Result | 4 tests / 22 assertions / green |

## Repository layout

```
Classes/
├── Command/
│   └── ScanCommand.php           ← Symfony command; knows nothing about Core internals
└── Scanner/
    ├── PathResolver.php          ← auto-detect packages/ and typo3conf/ext/
    ├── ScannerAdapter.php        ← *only* file with @internal Core imports
    └── Result/
        ├── FileScanResult.php
        └── Hit.php
Configuration/
└── Services.yaml                 ← DI + console.command tag
Tests/
├── Fixtures/                     ← known deprecation + clean counter-fixture
├── Functional/Command/
├── Functional/Scanner/
└── Functional/bootstrap.php
.ddev/
├── commands/web/test             ← `ddev test` helper, configures SQLite
└── config.yaml                   ← PHP 8.2 + MariaDB 10.11
composer.json
ext_emconf.php
phpunit.xml.dist
```

## License

GPL-2.0-or-later — same as the TYPO3 Core.
