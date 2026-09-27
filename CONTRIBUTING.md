# Contributing

## Running the tests

The functional tests boot a full TYPO3 via `typo3/testing-framework` on SQLite.
No database server, no DDEV:

```bash
composer install
typo3DatabaseDriver=pdo_sqlite php -d memory_limit=1G vendor/bin/phpunit -c phpunit.xml.dist
```

Without the raised memory limit PHP dies inside TYPO3's `TcaSchemaFactory`.

This extension supports two Core lines, so a change is only verified when both
are green:

```bash
composer update --with typo3/cms-core:^13.4 --with typo3/cms-install:^13.4 && \
  typo3DatabaseDriver=pdo_sqlite php -d memory_limit=1G vendor/bin/phpunit -c phpunit.xml.dist
composer update --with typo3/cms-core:^14.3 --with typo3/cms-install:^14.3 && \
  typo3DatabaseDriver=pdo_sqlite php -d memory_limit=1G vendor/bin/phpunit -c phpunit.xml.dist
```

`.github/workflows/tests.yml` runs the same as a matrix, plus monthly on a
schedule. The assertion count differs per line on purpose — see below.

## The one rule that matters here

Every reference to a Core `@internal` class lives in
`Classes/Scanner/ScannerAdapter.php`, and nowhere else. When a Core release
breaks the contract, that is the single file to touch. Please keep it that way:
an import of `TYPO3\CMS\Install\ExtensionScanner\…` in any other file makes the
next Core update a search instead of an edit.

Two tests guard the contract, and they do different jobs:

- `testDetectsRemovedClassNameAsStrongHit` uses a rule present in every ruleset
  since v9. It answers: does the scanner still run at all?
- `testRulesetComesFromTheInstalledCoreVersion` uses a rule that exists **only**
  in the v14 ruleset, and asserts its absence on older lines. It answers: are we
  reading the installed Core's rules, or a stale copy? Without it both matrix legs
  would assert the same thing, and a silently wrong ruleset would pass.

If you add a Core line, add a rule that is exclusive to it, in both directions.

## Findings and the exit code

`strong` means the matcher identified the symbol; `weak` means a name matched but
the receiver's type is unknowable by static analysis. Weak hits are frequent — 151
against 70 strong in a measured scan of 358 files — so `--fail-on` defaults to
`strong`. Please do not make weak hits fail by default again; it turns the command
into something that is always red.

## Commits

Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `chore:`). Say in the
message what was measured, not only what was changed: this repository sits on
someone else's internals, and the numbers are how the next reader knows whether a
claim still holds.
