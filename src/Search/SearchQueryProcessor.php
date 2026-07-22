<?php

namespace Zarbinco\PersianSearch\Search;

use Stringable;
use Zarbinco\PersianSearch\Exceptions\UnsupportedSearchQueryException;
use Zarbinco\PersianSearch\Text\SearchLocaleResolver;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final readonly class SearchQueryProcessor
{
    public function __construct(
        private SearchTextPipeline $pipeline,
        private SearchLocaleResolver $locales,
        private SearchQueryPolicy $policy,
    ) {}

    public function process(mixed $query, ?string $locale = null): ProcessedSearchQuery
    {
        $raw = $this->queryString($query);
        $resolvedLocale = $this->locales->resolve($locale);
        $originalLength = mb_strlen($raw, 'UTF-8');

        if ($originalLength > $this->policy->maximumLength
            && $this->policy->maximumLengthPolicy === MaximumLengthPolicy::Reject) {
            return new ProcessedSearchQuery(
                rawQuery: $raw,
                processedRawQuery: $raw,
                locale: $resolvedLocale,
                sanitizedQuery: '',
                normalizedQuery: '',
                tokens: [],
                searchableTokens: [],
                status: SearchQueryStatus::TooLong,
                wasTruncated: false,
                originalLength: $originalLength,
                processedLength: $originalLength,
            );
        }

        $wasTruncated = $originalLength > $this->policy->maximumLength;
        $processedRaw = $wasTruncated
            ? mb_substr($raw, 0, $this->policy->maximumLength, 'UTF-8')
            : $raw;
        $processedLength = mb_strlen($processedRaw, 'UTF-8');
        $prepared = $this->pipeline->prepare($processedRaw, $resolvedLocale);
        $searchableTokens = array_values(array_filter(
            $prepared->tokens,
            fn (string $token): bool => mb_strlen($token, 'UTF-8') >= $this->policy->minimumTokenLength,
        ));
        $searchableTokens = array_slice($searchableTokens, 0, $this->policy->maximumTokens);
        $status = $this->status($prepared->sanitized, $prepared->normalized, $searchableTokens);

        return new ProcessedSearchQuery(
            rawQuery: $raw,
            processedRawQuery: $processedRaw,
            locale: $prepared->locale,
            sanitizedQuery: $prepared->sanitized,
            normalizedQuery: $prepared->normalized,
            tokens: $prepared->tokens,
            searchableTokens: $searchableTokens,
            status: $status,
            wasTruncated: $wasTruncated,
            originalLength: $originalLength,
            processedLength: $processedLength,
        );
    }

    /** @param list<string> $searchableTokens */
    private function status(string $sanitized, string $normalized, array $searchableTokens): SearchQueryStatus
    {
        if ($sanitized === '') {
            return SearchQueryStatus::Empty;
        }

        if (preg_match('/[\p{L}\p{N}]/u', $normalized) !== 1) {
            return SearchQueryStatus::PunctuationOnly;
        }

        if (($this->policy->minimumLength > 0
                && mb_strlen($normalized, 'UTF-8') < $this->policy->minimumLength)
            || $searchableTokens === []) {
            return SearchQueryStatus::TooShort;
        }

        return SearchQueryStatus::Ready;
    }

    private function queryString(mixed $query): string
    {
        if ($query === null) {
            return '';
        }

        if (is_string($query) || $query instanceof Stringable) {
            return (string) $query;
        }

        throw UnsupportedSearchQueryException::forType(get_debug_type($query));
    }
}
