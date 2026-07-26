# Security Policy

## Supported releases

Security fixes are applied to the latest supported package release line.
Laravel 12 requires PHP 8.2 or later and Illuminate 12.61.1 or later within
Laravel 12. Laravel 13 requires PHP 8.3 or later and Illuminate 13.12.0 or later
within Laravel 13. Laravel 11 and earlier are outside the supported security
range. Minimum framework patch versions may move forward when active
advisories require a newer secure baseline. Until the first stable release,
security fixes target the current development branch.

## Reporting a vulnerability

Please use GitHub's private vulnerability reporting feature on the repository
Security tab. Do not open a public issue for a suspected vulnerability.

Include the affected version or commit, impact, reproduction steps, and any
known mitigation. Avoid including real credentials, personal data, or source
document content. The maintainers do not promise a fixed response deadline;
updates will be provided through the private report when available.

## Typo-correction safety

Spelling candidates are retrieved with bound parameters from validated table
identifiers. Runtime work is capped by token, delete-key, candidate-row, edit-
distance, and variant limits; no query text is interpolated into SQL and no
full dictionary edit-distance scan is performed. Keep these bounds finite when
customising configuration, and rebuild the dictionary only through trusted
operator workflows under the package maintenance lock.

Advanced correction generates a bounded set of locale-profile alternatives,
split positions, and adjacent merges in memory, then validates all required
terms with one parameterized dictionary lookup. Profile configuration accepts
only classes implementing `LanguageCorrectionProfile`; it does not evaluate
callbacks. Built-in profiles never bypass dictionary existence, locale
isolation, protected terms, query length/token policy, candidate-row limits, or
transformation-depth limits.

URLs, emails, decimal expressions, letter/digit codes, underscore identifiers,
and hyphenated identifiers are rejected conservatively before advanced
generation. Custom profiles must emit valid Unicode terms, finite iterables,
positive costs, one-character separators, stable locale metadata, and
non-sensitive rule identifiers. The engine stops consuming alternatives at the
configured bound even if an extension yields indefinitely.

Contextual correction accepts only safe Unicode word tokens that already exist
in the exact/base-locale dictionary. It rejects control/bidi characters, URLs,
emails, decimal forms, code-like syntax, identifiers, protected terms,
oversized queries, and excess tokens before candidate work. Dynamic connection
and table names are validated configuration identifiers; locales, hashes,
terms, partitions, and type filters remain bound SQL values. There is no
database-side edit distance, dictionary scan, per-row lookup, or unbounded
n-gram generation.

Keep contextual token, delete-key, candidate-row, composition, context-lookup,
result-count, query-length, and count-cap limits finite. Staging tables are
package-owned, build tokens are random, and final n-grams change only after
bounded staging succeeds. Fail-soft runtime paths catch only recognized missing
package tables and rethrow unrelated database failures.

Result gain is measured against the retained direct parent with
locale/partition/type memoization. Approximate or unavailable evidence cannot
authorize auto-apply. Full-collection insertion protects Original and every
retained lineage node. Per-locale dictionary/n-gram generation mismatches keep
contextual readiness false after partial or cross-connection build failures.

Optional popularity and click providers are evidence interfaces only. The
package persists no query analytics, click events, user identifiers, telemetry,
or personal data. Applications implementing them own consent, retention,
minimization, access control, and normalized aggregate output.
