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
- Verify phonetic and segmentation flags independently, built-in and custom
  profile validation, exact-to-base locale fallback, protected terms, one-query
  candidate validation per parent, bounded two-token phonetic composition,
  spelling-to-advanced provenance, split/merge bounds, invalid-early-pair merge
  handling, transformation depth, advanced suggestion evidence, and status
  readiness warnings.
- Publish and migrate the additive contextual n-gram schema on its configured
  connection; verify repeated `up()`, rollback, index shape, full and
  locale-scoped staged rebuilds, failure preservation, and status readiness.
- Verify contextual disabled/no-query and preview-off behavior, the direct
  trigger, valid-word eligibility, Persian/English corpus and bigram evidence,
  type filters, locale fallback/isolation, protected terms, bounded approximate
  counts, deterministic multi-token composition, non-word/advanced lineage,
  displayed-slice suggestions, and distinct advisory decisions.
- Verify original-versus-parent count separation, memoized parent baselines,
  zero-parent exact auto-apply safeguards, full-collection leaf replacement,
  semantic deduplication without unrelated eviction, lineage preservation,
  cross-parent global ranking, synonym-parent exclusion,
  all four result-count/n-gram flag combinations, and per-locale generation
  freshness after a failed n-gram rebuild, successful zero-row generation
  readiness, narrow missing-table fail-soft behavior, and removed-locale
  metadata cleanup after full but not locale-scoped rebuilds.
- Confirm analytics providers are neutral by default and store no query/click
  data, and contextual contracts plus facade annotations resolve publicly.
- Confirm an upgrade needs no additional migration, rebuilds the existing
  dictionary after enabling advanced vocabulary, and leaves edit-based spelling
  and keyboard variants unchanged when advanced flags are disabled.
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
actions; package checks and CI never perform them. The latest repository tag is
`v1.0.0`; if the unreleased spelling, advanced, and contextual correction work
ships together, the backward-compatible feature release recommendation is
`v1.1.0`.
