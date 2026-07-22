<?php

namespace Zarbinco\PersianSearch\Text;

use Zarbinco\PersianSearch\Contracts\SearchTextNormalizer;
use Zarbinco\PersianSearch\Contracts\SearchTextSanitizer;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchTextException;

final readonly class SearchTextPipeline
{
    public function __construct(
        private SearchTextValueConverter $converter,
        private SearchTextSanitizer $sanitizer,
        private SearchTextNormalizer $normalizer,
        private SearchTokenizer $tokenizer,
        private SearchLocaleResolver $locales,
    ) {}

    public function prepare(mixed $value, ?string $locale = null): PreparedSearchText
    {
        $locale = $this->locales->resolve($locale);
        $raw = $this->converter->convert($value);
        $sanitized = $this->sanitizer->sanitize($raw, $locale);
        $normalized = $this->normalizer->normalize($sanitized, $locale);
        $tokens = $this->validateTokens($this->tokenizer->tokenize($normalized, $locale));

        return new PreparedSearchText($locale, $raw, $sanitized, $normalized, $tokens);
    }

    /**
     * @param  array<int, mixed>  $tokens
     * @return list<string>
     */
    private function validateTokens(array $tokens): array
    {
        $validated = [];
        $seen = [];

        foreach ($tokens as $token) {
            if (! is_string($token) || $token === '') {
                throw InvalidSearchTextException::invalidTokens();
            }

            if (! isset($seen[$token])) {
                $seen[$token] = true;
                $validated[] = $token;
            }
        }

        return $validated;
    }
}
