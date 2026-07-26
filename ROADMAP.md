# Package Roadmap

This roadmap separates the approved package baseline from possible future
work. The completed correction features described below are intended for the
`v1.1.0` release line. Roadmap items are design targets, not implemented
capabilities, support commitments, or delivery promises.

## Status

| Capability | Status |
| --- | --- |
| Multilingual non-word typo correction | Complete |
| Phonetic and split/merge correction | Complete |
| Multi-token correction composition | Complete |
| Real-word contextual correction | Complete |
| Parent baseline and flag integrity | Complete |
| Semantic deduplication and readiness | Complete |
| Multilingual transliteration | Roadmap |
| Mixed-script and mixed-language composition | Roadmap |
| Large-scale composition and performance hardening | Roadmap |
| Multi-database compatibility and release certification | Roadmap |

## Current `v1.1.0` boundary

The current approved feature line includes bounded keyboard-layout, spelling,
phonetic, split/merge, multi-token, and contextual correction with typed
provenance, dictionary/readiness operations, and displayed-result-sensitive
suggestions. It does not include the roadmap below.

Current `v1.1.0` non-goals are:

- transliteration;
- automatic translation;
- general grammar correction;
- semantic rewriting;
- neural or LLM correction;
- embeddings;
- external search services;
- application UI;
- personal query tracking;
- click-tracking storage;
- unlimited n-grams;
- unbounded recursive correction.

## Multilingual Transliteration

### Objective

Convert a token written phonetically in one script into bounded candidate terms
from the target locale/domain dictionary, for example:

```text
porteghal → پرتقال
bastani   → بستنی
mohito    → موهیتو
```

### Motivation

Users sometimes know how a term sounds but enter it in a different script.
Wrong keyboard-layout correction maps physical keys, while transliteration maps
sound-oriented spellings across scripts. Neither operation is translation.

### Planned scope

- Detect script per token before choosing a transliteration profile.
- Use conservative, locale-profile-driven rules with exact-to-base regional
  fallback.
- Require every accepted output term to exist in the selected locale/domain
  dictionary.
- Bound generated forms, dictionary lookups, accepted candidates, and retained
  variants.
- Provide a conservative Persian/Latin reference profile that applications can
  replace.
- Preserve protected terms and expose typed provenance to the existing
  suggestion pipeline.
- Require no external neural service in the default implementation.

Potential architecture may include `TransliterationProfile`,
`TransliterationCandidateGenerator`, `ScriptDetector`, and
`TransliterationCorrection`. These types do not currently exist.
Application-specific brand, catalog, and product aliases remain
application-owned.

### Explicit non-goals

- Translation, semantic substitution, grammar correction, or arbitrary
  rewriting.
- An exhaustive universal romanization standard.
- Full dictionary scans or one database query per candidate.
- Built-in ownership of application aliases.

### Architectural considerations

Transliteration should enter the existing bounded variant graph as a
non-recursive stage. Profiles need stable identifiers, deterministic rule
ordering, safe token/script validation, dictionary-batched acceptance, locale
fallback, semantic deduplication, and complete parent fingerprints. Disabled
resolution must remain lazy and add no runtime query.

### Acceptance criteria

- An opt-in feature flag with exact backward compatibility when disabled.
- A conservative Persian/Latin reference profile and replaceable application
  profiles.
- Per-token script detection, regional locale fallback, and protected-term
  support.
- Deterministic bounded ranking with no full dictionary scan and no query per
  candidate.
- Typed provenance and effective-suggestion integration.
- Tests for valid examples, ambiguity, unsafe tokens, unsupported scripts,
  locale isolation, bounds, and disabled behavior.

### Dependencies

An approved profile contract, script classification policy, dictionary
eligibility design, variant priority allocation, and representative evaluation
fixtures are required before implementation.

### Risks

Romanization is ambiguous and domain-sensitive. Broad profiles may create
false positives, interact unpredictably with protected terms, or expand work
too quickly. Conservative defaults may intentionally miss valid spellings.

### Suggested release milestone

A post-`v1.1.0` opt-in feature release after profile design, bounded-query
proof, and precision review; assign a version only when that scope is approved.

## Mixed-script and Mixed-language Composition

### Objective

Compose bounded corrections when tokens within one query use different scripts
or probable languages, for example:

```text
sunich porteghal
orange بستنی
سن ایچ orange juice
```

### Motivation

Real search queries can mix localized product terms, Latin brand spellings, and
different scripts. Request/page locale alone is insufficient to select safe
token-level correction behavior.

### Planned scope

- Detect script and probable locale per token while preserving page/request
  locale as separate metadata.
- Combine transliteration with existing keyboard, spelling, phonetic,
  split/merge, and contextual stages.
- Apply one strict global transformation-depth limit across the complete
  composition.
- Retain full ordered provenance and valid parent fingerprints.
- Prevent recursive expansion, candidate explosion, duplicate semantic
  variants, and duplicate result searches.
- Preserve exact and protected tokens.
- Keep displayed-result suggestion evidence tied to the exact retained
  fingerprint.

### Explicit non-goals

- Automatic translation.
- Grammar correction.
- Semantic rewriting.
- Language-model generation.
- Inferring user identity or preference from query history.

### Architectural considerations

Token language is evidence, not certainty. Composition needs a deterministic
state model carrying request locale, token locale/script decisions, cumulative
depth, parent identity, and transformation history. Global budgets must apply
before stages form a Cartesian product. Contextual output must not become a new
contextual parent.

### Acceptance criteria

- Token-level script/probable-locale detection with explicit uncertainty
  handling.
- Mixed-script fixtures across supported locale chains.
- Deterministic candidate and variant ranking under fixed global bounds.
- Bounded query counts and transformation depth.
- No semantic duplicate variants or orphaned parent fingerprints.
- Correct displayed-result suggestion provenance.
- Exact/protected tokens remain unchanged and disabled behavior remains
  backward compatible.

### Dependencies

contracts and profiles, global budget semantics, token-locale metadata,
and cross-stage provenance rules must be approved first.

### Risks

Language detection on short tokens is unreliable. Combining individually
bounded stages can still multiply work, and incorrect token locale inference
can reduce precision or cross locale boundaries.

### Suggested release milestone

A separate feature milestone after mixed-script fixtures and
global composition bounds are proven; no version is assigned yet.

## Large-scale Composition and Performance Hardening

### Objective

Validate and harden the complete correction engine against large,
multi-locale, realistic corpora using portable work bounds rather than
machine-specific latency promises.

### Motivation

Per-stage limits do not by themselves prove that combined search, correction,
context, indexing, and rebuild work stays bounded at larger scale.

### Planned scope

- A central per-request SQL query budget.
- A global candidate budget across all correction stages.
- A CPU/work-unit budget with deterministic exhaustion behavior.
- Transformation-depth accounting across keyboard, spelling, phonetic,
  segmentation, contextual, and transliteration stages.
- Request-level candidate-count memoization.
- Reusable dictionary and readiness caches with explicit invalidation.
- Deterministic eviction of low-priority variants.
- Prevention of duplicate database searches.
- Queue and dictionary-rebuild observability.
- Application hooks for slow-query diagnostics without collecting personal
  query data.
- Corpus-size benchmarks, memory-bounded dictionary builds, and large
  multi-locale fixtures.
- Failure/recovery and concurrent index/dictionary rebuild tests.

### Explicit non-goals

- Guaranteed millisecond targets without benchmark evidence.
- Unbounded caching or process-global mutable query state.
- Relaxing semantic correctness, provenance, or failure handling for speed.
- Requiring a particular cache, queue, or external search product.

### Architectural considerations

Budgets should count stable units such as SQL statements, rows examined,
candidates evaluated, retained variants, context-result searches, generated
states, and rebuild batch windows. Cache keys must include relevant connection,
table, locale, generation, policy, and filter identity. Exhaustion must be
deterministic and observable without exposing raw queries.

### Acceptance criteria

- Enforced and tested maxima for SQL queries per request, retained variants,
  candidates evaluated, dictionary rows examined, context-result searches, and
  rebuild memory windows.
- Deterministic output when any budget is exhausted.
- No duplicate database searches for equivalent request evidence.
- Large multi-locale fixtures and reproducible corpus-size benchmark reports.
- Concurrency, interruption, stale-cache, and rebuild-recovery coverage.
- Bounded-work assertions that do not depend on one machine's timing.

### Dependencies

Stable composition stages, an agreed work-accounting model, representative
datasets, database instrumentation, and cache invalidation tied to build
generations are required.

### Risks

Instrumentation can itself add overhead. Incorrect cache identity or
invalidation can mix locales or stale generations. Synthetic corpora may fail
to represent real candidate distributions.

### Suggested release milestone

A hardening milestone after composition features stabilize; release naming
should follow measured compatibility and behavior impact.

## Multi-database Compatibility and Release Certification

### Objective

Certify installation, upgrade, runtime, operations, and release packaging on
the databases and framework/runtime matrix the project actually tests.

### Motivation

SQLite unit/feature coverage and SQL grammar review do not substitute for real
database integration, upgrade, queue, connection-topology, and packaging
verification.

### Planned scope

- Real integration suites for SQLite, MySQL, MariaDB, and PostgreSQL.
- Laravel and PHP support-matrix jobs matching declared Composer constraints.
- Installation from `v1.0.0`, upgrade from `v1.0.0` to the current feature
  line, and fresh installation.
- Migration publishing, repeated application, rollback policy, and configured
  package connections.
- Separate index, spelling, and contextual connection topologies.
- Queue-backed lifecycle behavior, package discovery, configuration caching,
  optimized autoloading, and dependency-conflict review.
- Release archive integrity, reproducible packaging, checklist automation, CI
  matrix maintenance, security review, and a production operations guide.
- Optional SQL Server certification only if maintainers decide to support it
  and add real integration coverage; no SQL Server support is claimed here.

### Explicit non-goals

- Claiming compatibility based only on SQL syntax inspection.
- Adding untested databases to the support statement.
- Hiding driver-specific behavior behind skipped or weakened assertions.
- Shipping internal delivery artifacts in the package archive.

### Architectural considerations

CI must exercise real database services and driver-specific missing-table,
transaction, locking, identifier, index, and bulk-write behavior. Upgrade
fixtures must begin from released artifacts, not only current migrations.
Separate-connection tests must retain the documented lack of cross-connection
atomicity.

### Acceptance criteria

- All declared CI matrix jobs are green.
- Fresh install and `v1.0.0` upgrade paths are verified.
- Rollback policy is documented and tested.
- Configured and separate connection topologies pass.
- Package discovery, config cache, queue lifecycle, and optimized autoload pass.
- No internal delivery artifacts are present.
- The release archive is reproducible.
- Composer validation, Pint, PHPStan, and the full PHPUnit suite are green.
- Support documentation names only tested databases and runtime combinations.

### Dependencies

Maintained CI services/images, released `v1.0.0` fixtures, matrix ownership,
database-specific diagnostics, packaging automation, and security/release
review capacity are required.

### Risks

Database and framework matrices are expensive to maintain. Driver versions,
collations, isolation behavior, and dependency security constraints can expose
real incompatibilities that require narrowing support rather than adding
workarounds.

### Suggested release milestone

Certification is a release gate for any support claims it covers. Assign the
milestone version only after the tested matrix and compatibility policy are
approved.

## Research backlog

The following topics are lower-priority research, not promised features:

- optional aggregate query-popularity signals;
- optional aggregate click-confidence signals;
- domain-specific language profiles;
- additional keyboard layouts;
- additional transliteration profiles;
- phrase models beyond bigrams;
- application-provided synonym governance;
- offline evaluation datasets;
- precision/recall measurement;
- false-positive review tooling;
- correction explainability diagnostics;
- administrative dictionary inspection tools.

The package must not collect personal query data by default. Analytics
integrations remain opt-in and application-owned, including consent, storage,
retention, and aggregation. LLMs, embeddings, and external search engines are
not required by the core package. Any future neural integration must sit behind
an optional contract and must not alter the default lightweight engine.

## Maintaining this roadmap

Before describing a roadmap item as complete, link its implementation,
configuration, migrations where applicable, tests, operations guidance, and
release evidence. Keep release claims aligned with the tested support matrix,
and move uncertain ideas to the research backlog rather than implying support.
