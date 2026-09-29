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
the scan to be scriptable, you can reach for a standalone reimplementation
such as `michielroos/typo3scan`, for `netresearch/nr-extension-scanner-cli`
(same approach as this one, published later and independently), or write a
small command that talks to the Core's own scanner directly. This repo is
the last option, deliberately bound to the installed Core version so the
match list always tracks the Core that's actually installed.

## ⚠ Deliberate use of `@internal` Core classes

Every class under
`typo3/sysext/install/Classes/ExtensionScanner/Php/` is marked
`@internal` in the Core. Their signatures may change between major
Core releases without a deprecation path. This is a deliberate
trade-off — it's the price of reusing the Core's own match
definitions.

**Measured across one major, 2026-09-27.** Between TYPO3 v13.4.35 and
v14.3.7 the `ExtensionScanner` directory kept an identical file list and
changed in **no** public signature; the 227 diff lines are internal
hardening (`isset($node->name->name)` became
`$node->name instanceof Identifier`). The 23-entry matcher registry is
identical in both lines, and both resolve the parser to
`PhpVersion::fromComponents(8, 2)`. The risk is real, but for this major
it did not materialise — no line of `Classes/` needed a change to support
v14.

All references to these internal classes are concentrated in **one**
file: [`Classes/Scanner/ScannerAdapter.php`](Classes/Scanner/ScannerAdapter.php).
When a Core update breaks the contract, that's the single place to
touch.

The matcher registry in the adapter mirrors
`vendor/typo3/cms-install/Classes/Controller/UpgradeController.php`.
Its 23 entries are the same in the **v13.4** and **v14.3** lines, checked
entry by entry against both.

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

In the `composer.json` of your TYPO3 13.4 or 14.3 project:

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

# Gate a pipeline on strong findings only (the default)
vendor/bin/typo3 t3x:extensionscanner:scan --fail-on=strong

# Fail on weak findings too, or never fail at all
vendor/bin/typo3 t3x:extensionscanner:scan --fail-on=any
vendor/bin/typo3 t3x:extensionscanner:scan --fail-on=none

# Override directory excludes (default: vendor, node_modules, .Build, var, .git)
vendor/bin/typo3 t3x:extensionscanner:scan packages/ --exclude=vendor --exclude=Tests
```

### Options

| Option | Default | Meaning |
|---|---|---|
| `paths` (argument, variadic) | auto-detect | Files or directories to scan. |
| `--format` | `table` | `table` (human-readable) or `json` (machine-readable). |
| `--exclude` | see default | Directory names to skip. |
| `--fail-on` | `strong` | Which findings make the exit code non-zero: `strong`, `any` or `none`. A parse or scan error fails `strong` and `any`. |
| `--no-fail` | off | Alias for `--fail-on=none`. |

### Exit codes

| Code | Meaning |
|---|---|
| `0` | No finding that the chosen `--fail-on` gates on. |
| `1` | Such a finding exists, or a file could not be parsed or scanned (except with `--fail-on=none`). |
| `2` | Invalid invocation (non-existent path, unknown format, unknown `--fail-on`). |

`strong` means the matcher identified the symbol. `weak` means a method or
property name matched but the receiver's type is not knowable by static
analysis — a name collision is as likely as a finding. Weak hits are frequent:
a scan of 358 files of real extension code produced 70 strong and **151 weak**
hits. That is why `--fail-on` defaults to `strong`; gating on weak hits gates on
every project, which says nothing. The report always lists both.

### JSON schema

```json
{
  "summary": {
    "filesScanned": 42,
    "filesWithHits": 3,
    "filesIgnored": 0,
    "filesWithParseErrors": 0,
    "filesWithScanErrors": 0,
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
      "scanError": null,
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

`results` lists only files that have hits, a parse error or a scan error. The
counters in `summary` cover all scanned files.

A scan error means a Core matcher failed on the file, for example
`Undefined property: PhpParser\Node\VariadicPlaceholder::$unpack` on a static
first-class callable in TYPO3 v14.3 ([Forge #110828](https://forge.typo3.org/issues/110828)). The run goes on
with the next file. A file with a scan error reports no hits, because the hits
collected before the failure are incomplete.

### Ignore annotations

The annotations used by the Core's backend module are honored:

- `@extensionScannerIgnoreFile` (on a class) → the entire file is
  skipped.
- `@extensionScannerIgnoreLine` (in a comment above a line) → the
  single line is skipped.

## Development & tests

The functional tests boot a complete TYPO3 instance via
`typo3/testing-framework` and run on SQLite — no database server, and no
DDEV either:

```bash
composer install
typo3DatabaseDriver=pdo_sqlite php -d memory_limit=1G vendor/bin/phpunit -c phpunit.xml.dist
```

Without the raised memory limit PHP dies inside TYPO3's `TcaSchemaFactory`.
DDEV still works if you prefer it:

```bash
ddev start
ddev composer install
ddev test
```

To switch the line under test, pin the Core packages for one run:

```bash
composer update --with typo3/cms-core:^14.3 --with typo3/cms-install:^14.3
```

`.github/workflows/tests.yml` runs exactly that as a matrix over v13.4 and
v14.3 against PHP 8.2 and 8.4, plus once a month on a schedule — so a
broken `@internal` contract shows up even when nobody touches this
repository.

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
  must produce no hits at all. That rule exists in every ruleset since v9, so
  this test answers "does the scanner still run" — not "whose rules is it
  using".
- The same file also scans
  [`Tests/Fixtures/V14OnlyRule.php`](Tests/Fixtures/V14OnlyRule.php), whose rule
  exists **only** in the v14 ruleset (`TYPO3\CMS\Core\Service\FlexFormService`,
  Breaking-107945), and asserts its *absence* on older lines. This is the test
  that would catch a stale or foreign ruleset, and it is why the two matrix legs
  assert a different number of things: 47 assertions on v13.4, 49 on v14.3.
- [`Tests/Functional/Command/ScanCommandTest.php`](Tests/Functional/Command/ScanCommandTest.php)
  drives the command through
  `Symfony\Component\Console\Tester\CommandTester`, validates the
  JSON schema, and checks the gate: weak hits alone exit `0`, a strong hit
  exits `1`, `--fail-on=any` fails on weak hits too, `--fail-on=none` never
  fails, and an unknown value is an invalid invocation rather than a pass. The
  weak-only case has its own fixture,
  [`Tests/Fixtures/Weak/WeakHitOnly.php`](Tests/Fixtures/Weak/WeakHitOnly.php),
  which contains no class name at all so no matcher can report `strong`.

When a Core update breaks the internal contract, these tests are the
first thing to fail.

### Verified state

| | | |
|---|---|---|
| TYPO3 Core | v13.4.35 | v14.3.7 |
| PHP | 8.4.26 | 8.4.26 |
| testing-framework | 9.7.0 | 9.7.0 |
| PHPUnit | 11.5.56 | 11.5.56 |
| Verified | 2026-09-27 | 2026-09-27 |
| Result | 10 tests / 33 assertions / green | 10 tests / 35 assertions / green |

The first verification was v13.4.30 with PHP 8.2.30 and testing-framework
9.5.0 on 2026-05-27, also green.

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
LICENSE  CHANGELOG.md  CONTRIBUTING.md
Tests/
├── Fixtures/                     ← known deprecation, clean counter-fixture,
│   │                               a v14-only rule, and a weak-only case
│   └── Weak/WeakHitOnly.php
├── Functional/Command/
├── Functional/Scanner/
└── Functional/bootstrap.php
.github/workflows/
└── tests.yml                     ← v13.4 / v14.3 matrix, monthly schedule
.ddev/
├── commands/web/test             ← `ddev test` helper, configures SQLite
└── config.yaml                   ← PHP 8.2 + MariaDB 10.11
composer.json
ext_emconf.php
phpunit.xml.dist
```

## License

GPL-2.0-or-later — same as the TYPO3 Core. Full text in [`LICENSE`](LICENSE).
See [`CONTRIBUTING.md`](CONTRIBUTING.md) before changing the adapter, and
[`CHANGELOG.md`](CHANGELOG.md) for what changed when.
