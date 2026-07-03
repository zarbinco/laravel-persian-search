# laravel-persian-search

`zarbinco/laravel-persian-search` provides Laravel-facing search utilities for Persian applications, powered by `zarbinco/laravel-persian-core`.

## Overview

The package currently provides the foundation for Persian-aware search features:

- A Laravel service provider with package auto-discovery
- A publishable configuration file
- A `PersianSearch` facade
- A `PersianSearchManager`
- A `SearchNormalizer` contract
- A core-backed search normalizer that delegates to `laravel-persian-core`

It does not currently provide indexing, database search, relevance ranking, Scout integration, or external search-engine integrations.

## Installation

Install the package with Composer:

```bash
composer require zarbinco/laravel-persian-search
```

The package depends on `zarbinco/laravel-persian-core` and uses Laravel package auto-discovery.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=persian-search-config
```

The default configuration reserves settings for database-backed search, indexing, ranking, synonyms, and wrong-keyboard typing correction. Some of these settings are reserved for planned capabilities and are not active search features yet.

## Usage

```php
use Zarbinco\PersianSearch\Facades\PersianSearch;

PersianSearch::normalize('كیكِ شکلاتي');

PersianSearch::tokens('آب‌میوه سن‌ایچ');
```

Both methods delegate to `laravel-persian-core`:

```php
Persian::search($value)->normalize();
Persian::search($value)->tokens();
```

## Current Features

- Search normalization through `laravel-persian-core`
- Search token generation through `laravel-persian-core`
- Container binding for `Zarbinco\PersianSearch\Contracts\SearchNormalizer`
- Manager and facade access to normalization and tokenization
- Publishable package configuration
- PHPUnit, Larastan/PHPStan, and Laravel Pint setup

## Planned Capabilities

- Searchable model contracts
- Search document builders
- Database index storage
- Database search driver
- Relevance ranking
- Query expansion
- Synonyms
- Wrong-keyboard typing correction
- Documentation and release-readiness improvements

Wrong-keyboard typing correction is a core feature planned for this package. It belongs to `laravel-persian-search` as query candidate expansion, not to `laravel-persian-core` as text normalization.

## Boundaries

`laravel-persian-core` owns Persian text normalization, digit conversion, punctuation cleanup, ZWNJ handling, and tokenization.

`laravel-persian-search` starts after that boundary. It must not duplicate Persian normalization logic, character maps, digit conversion, punctuation cleanup, ZWNJ handling, or tokenization rules already provided by `laravel-persian-core`.

## Testing

```bash
composer validate --strict
composer test
composer analyse
composer format -- --test
```
