# Contributing

Thank you for helping improve Laravel Persian Search.

Laravel 12 requires PHP 8.2 or later and Illuminate 12.61.1 or later within
Laravel 12, paired with Testbench 10. Laravel 13 requires PHP 8.3 or later and
Illuminate 13.12.0 or later within Laravel 13, paired with Testbench 11.
Laravel 11 and earlier are not supported. Changes must preserve both supported
framework lines and their secure minimums.

## Setup

```bash
composer install
```

## Running Tests

```bash
composer check
```

## Static Analysis

```bash
composer analyse
```

## Formatting

```bash
composer format
composer format:test
```

Do not commit `vendor`, coverage, temporary SQLite databases, generated
reports, or delivery archives.

Database integration tests use Testbench with an in-memory SQLite connection
unless a test is explicitly grammar-only. Tests must not require external
cache, queue, or database services. Operational tests must prove dry-run,
locking, bounded scans, deterministic output, and failure behavior.

## Pull Requests

- Keep changes focused and covered by tests.
- Do not duplicate Persian normalization or tokenization logic from `zarbinco/laravel-persian-core`.
- Update documentation when public behavior changes.
- Run validation, tests, static analysis, and formatting checks before submitting.
- Keep pull requests scoped; separate relevance changes from operational or
  package-maintenance changes.
- Add coverage for every supported PHP/Laravel behavior changed by the pull
  request.
