# Release Checklist

- Verify Laravel 12 requires PHP 8.2 or later and Illuminate 12.61.1 or later
  within Laravel 12.
- Verify Laravel 13 requires PHP 8.3 or later and Illuminate 13.12.0 or later
  within Laravel 13; Laravel 11 and earlier are not supported.
- Start from a clean working tree and review the intended version and
  `CHANGELOG.md`.
- Run `composer validate --strict`, `composer dump-autoload --optimize --strict-psr`,
  `composer audit`, and `composer check`.
- Confirm both lockless highest and lowest-secure CI jobs are green: Laravel 12
  with Testbench 10 and Laravel 13 with Testbench 11, across their documented
  PHP versions.
- Confirm `composer.lock` is absent and remains ignored for this library.
- Install into a fresh supported Laravel test application.
- Verify package auto-discovery, configuration publication, migration
  publication, and fresh search-document plus spelling-dictionary migrations.
- Build locale-scoped and full spelling dictionaries, inspect status/staleness,
  and verify disabled, fail-soft, and fail-closed runtime modes.
- Exercise source save/delete/restore, enumerator reindex dry-run and write
  modes, prune dry-run and execute modes, status, and doctor.
- Review dependency constraints, database portability, queue/cache
  self-containment, security-sensitive output, and all public APIs.
- Search for debug calls, credentials, raw source identities, temporary files,
  generated coverage/reports, and committed `vendor` content.
- Confirm release artifacts contain only intended files.
- Manually create the version tag, publish the GitHub release, and submit
  package metadata only after all preceding checks pass.

Tagging, publishing, and release creation are deliberately manual future
actions; package checks and CI never perform them.
