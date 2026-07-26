<?php

namespace Zarbinco\PersianSearch\Query;

use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SpellingCorrector;
use Zarbinco\PersianSearch\Contracts\SynonymExpander;
use Zarbinco\PersianSearch\Search\ProcessedSearchQuery;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\QueryVariantSource;

final readonly class DefaultQueryExpander implements QueryExpander
{
    public function __construct(
        private QueryVariantPolicy $policy,
        private KeyboardLayoutCorrector $keyboard,
        private SynonymExpander $synonyms,
        private ?SpellingCorrector $spelling = null,
    ) {}

    public function original(ProcessedSearchQuery $query): QueryVariantCollection
    {
        $variants = new QueryVariantCollection($this->policy->maximumVariants);

        if (! $query->isSearchable()) {
            return $variants;
        }

        return $variants->with(new QueryVariant(
            query: $query->normalizedQuery,
            locale: $query->locale,
            tokens: $query->searchableTokens,
            source: QueryVariantSource::Original,
            priority: $this->policy->priority(QueryVariantSource::Original),
            fingerprint: $this->fingerprint(QueryVariantSource::Original, $query->normalizedQuery, $query->locale),
        ));
    }

    public function expand(ProcessedSearchQuery $query): QueryVariantCollection
    {
        $variants = $this->original($query);
        $original = $variants->original();

        if ($original === null || $variants->isFull()) {
            return $variants;
        }

        $keyboard = null;
        $correction = $this->keyboard->correct($original, $query->sanitizedQuery);

        if ($correction !== null) {
            $keyboard = new QueryVariant(
                query: $correction->correctedQuery,
                locale: $correction->targetLocale,
                tokens: $correction->tokens,
                source: QueryVariantSource::Keyboard,
                priority: $this->policy->priority(QueryVariantSource::Keyboard),
                fingerprint: $this->fingerprint(QueryVariantSource::Keyboard, $correction->correctedQuery, $correction->targetLocale, $original->fingerprint, $correction->fingerprint),
                parentFingerprint: $original->fingerprint,
                keyboardCorrection: $correction,
            );
            $variants = $variants->with($keyboard);
        }

        $variants = $this->addSpelling($variants, $original, QueryVariantSource::Spelling);
        if ($keyboard !== null) {
            $variants = $this->addSpelling($variants, $keyboard, QueryVariantSource::KeyboardSpelling);
        }

        $variants = $this->addSynonyms($variants, $original, QueryVariantSource::Synonym);

        if ($keyboard !== null) {
            $variants = $this->addSynonyms($variants, $keyboard, QueryVariantSource::KeyboardSynonym);
        }

        return $variants;
    }

    private function addSpelling(QueryVariantCollection $variants, QueryVariant $parent, QueryVariantSource $source): QueryVariantCollection
    {
        if ($variants->isFull() || $this->spelling === null) {
            return $variants;
        }

        foreach ($this->spelling->correct($parent) as $correction) {
            $variants = $variants->with(new QueryVariant(
                query: $correction->correctedQuery,
                locale: $correction->locale,
                tokens: $correction->tokens,
                source: $source,
                priority: $this->policy->priority($source),
                fingerprint: $this->fingerprint($source, $correction->correctedQuery, $correction->locale, $parent->fingerprint, $correction->fingerprint),
                parentFingerprint: $parent->fingerprint,
                keyboardCorrection: $parent->keyboardCorrection,
                spellingCorrection: $correction,
            ));

            if ($variants->isFull()) {
                return $variants;
            }
        }

        return $variants;
    }

    private function addSynonyms(QueryVariantCollection $variants, QueryVariant $parent, QueryVariantSource $source): QueryVariantCollection
    {
        if ($variants->isFull()) {
            return $variants;
        }

        foreach ($this->synonyms->expand($parent) as $expansion) {
            $variants = $variants->with(new QueryVariant(
                query: $expansion->query,
                locale: $expansion->locale,
                tokens: $expansion->tokens,
                source: $source,
                priority: $this->policy->priority($source),
                fingerprint: $this->fingerprint($source, $expansion->query, $expansion->locale, $parent->fingerprint, $expansion->fingerprint),
                parentFingerprint: $parent->fingerprint,
                keyboardCorrection: $parent->keyboardCorrection,
                spellingCorrection: $parent->spellingCorrection,
                appliedSynonyms: [...$parent->appliedSynonyms, $expansion],
            ));

            if ($variants->isFull()) {
                return $variants;
            }
        }

        return $variants;
    }

    private function fingerprint(QueryVariantSource $source, string $query, string $locale, ?string $parent = null, ?string $operation = null): string
    {
        return hash('sha256', implode("\0", [$source->value, $query, $locale, $parent ?? '', $operation ?? '']));
    }
}
