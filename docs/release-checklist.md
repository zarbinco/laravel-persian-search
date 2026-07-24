# Release Checklist

- Start from a clean working tree and review the intended version and
  `CHANGELOG.md`.
- Run `composer validate --strict`, `composer dump-autoload --optimize --strict-psr`,
  `composer audit`, and `composer check`.
- Confirm the complete supported PHP/Laravel CI matrix is green.
- Install into a fresh supported Laravel test application.
- Verify package auto-discovery, configuration publication, migration
  publication, and a fresh search-document migration.
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
