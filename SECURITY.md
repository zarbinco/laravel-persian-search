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
