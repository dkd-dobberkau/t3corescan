# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Support for TYPO3 v14.3 alongside v13.4. No line of `Classes/` changed: between
  v13.4.35 and v14.3.7 the `@internal` `ExtensionScanner` classes kept an identical
  file list and no changed public signature, and the 23-entry matcher registry is
  the same in both lines. Only the Composer constraints and the package metadata
  needed opening.
- `extra.typo3/cms.version` and an empty `Package.providesPackages` in
  `composer.json`. Without them TYPO3 v14.3 triggers Deprecation-108345 during
  cache warm-up, and v15 will require them outright. `ext_emconf.php` stays,
  because v13.4 in classic mode still needs it.
- `--fail-on=strong|any|none` to choose which findings make the exit code
  non-zero. Default `strong`.
- `.github/workflows/tests.yml`: the functional tests run as a matrix over v13.4
  and v14.3 against PHP 8.2 and 8.4, on push, on pull request, and once a month —
  so a broken `@internal` contract surfaces even when nobody touches this
  repository.
- A fixture whose rule exists only in the v14 ruleset
  (`TYPO3\CMS\Core\Service\FlexFormService`, Breaking-107945). The older tests
  would pass on any Core line, so they proved "the scanner still runs" rather than
  "the scanner uses the installed Core's rules". The assertion count now differs
  per line: 33 on v13.4, 35 on v14.3.
- `LICENSE`, `CHANGELOG.md`, `CONTRIBUTING.md`.

### Changed

- **Weak hits no longer fail the command by default.** `MethodCallMatcher` cannot
  know a receiver's type, so it reports every call to a known method name as weak;
  a measured scan of 358 files of real extension code produced 70 strong and 151
  weak hits. Failing on weak hits means failing on every project, which made the
  exit code useless as a gate. Use `--fail-on=any` for the previous behaviour.
- `--no-fail` is kept as an alias for `--fail-on=none`.
- **A parse or scan error now fails the command**, for `--fail-on=strong` and
  `--fail-on=any`. Before, a file the scanner could not read passed the gate
  without having been looked at, the same way the backend module counts such a
  file as checked. `--fail-on=none` still exits 0.

### Fixed

- **A matcher that fails on one file no longer ends the run.** The Core matchers
  are `@internal` and not hardened against every node type. In v14.3 and on main,
  a static first-class callable such as `Foo::bar(...)` makes
  `AbstractCoreMatcher::isArgumentUnpackingUsed()` read `$arg->unpack` on a
  `PhpParser\Node\VariadicPlaceholder`, which has no such property
  ([Forge #110828](https://forge.typo3.org/issues/110828)). Under the default `SYS/exceptionalErrors` that
  warning became an exception and the command stopped at the first such file.
  `ScannerAdapter::scanFile()` now turns warnings and notices from the matchers
  into an exception, catches it and returns the file with a `scanError` and no
  hits, because the hits of an aborted file are incomplete. The JSON output
  carries `scanError` per file and `filesWithScanErrors` in the summary, the table
  a `scan-error` row.
- `ScannerAdapter` accepts an optional matcher registry, so the tests can inject a
  failing matcher and do not depend on the Core still having this bug.

## [0.1.0] - 2026-05-28

### Added

- `t3x:extensionscanner:scan`, exposing the Core Extension Scanner of
  `EXT:install` as a Symfony Console command: table and JSON output, directory
  excludes, ignore annotations, documented JSON schema, exit codes.
- Functional tests over `typo3/testing-framework` on SQLite, verified against
  TYPO3 v13.4.30.
