<?php

namespace Zarbinco\PersianSearch\Query;

use Zarbinco\PersianSearch\Contracts\AdvancedQueryCorrector;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SpellingCorrector;
use Zarbinco\PersianSearch\Contracts\SynonymExpander;
use Zarbinco\PersianSearch\Correction\CorrectionTransformationType;
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
        private ?AdvancedQueryCorrector $advanced = null,
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

        /** @var list<QueryVariant> $spellingParents */
        $spellingParents = [];
        $variants = $this->addSpelling($variants, $original, QueryVariantSource::Spelling, $spellingParents);
        if ($keyboard !== null) {
            $variants = $this->addSpelling(
                $variants,
                $keyboard,
                QueryVariantSource::KeyboardSpelling,
                $spellingParents,
            );
        }

        /** @var array<string, true> $advancedParents */
        $advancedParents = [];
        foreach ($spellingParents as $spellingParent) {
            if (! $this->retains($variants, $spellingParent->fingerprint)) {
                continue;
            }
            $variants = $this->addAdvanced(
                $variants,
                $spellingParent,
                $spellingParent->keyboardCorrection !== null,
                $advancedParents,
            );
        }
        $variants = $this->addAdvanced($variants, $original, false, $advancedParents);
        if ($keyboard !== null) {
            $variants = $this->addAdvanced($variants, $keyboard, true, $advancedParents);
        }

        $variants = $this->addSynonyms($variants, $original, QueryVariantSource::Synonym);

        if ($keyboard !== null) {
            $variants = $this->addSynonyms($variants, $keyboard, QueryVariantSource::KeyboardSynonym);
        }

        return $variants;
    }

    /** @param array<string, true> $expandedParents */
    private function addAdvanced(
        QueryVariantCollection $variants,
        QueryVariant $parent,
        bool $keyboard,
        array &$expandedParents,
    ): QueryVariantCollection {
        if ($variants->isFull() || $this->advanced === null) {
            return $variants;
        }
        $semanticKey = $parent->semanticKey();
        if (isset($expandedParents[$semanticKey])) {
            return $variants;
        }
        $expandedParents[$semanticKey] = true;

        foreach ($this->advanced->correct($parent) as $correction) {
            $source = match ([$correction->type(), $keyboard]) {
                [CorrectionTransformationType::Phonetic, false] => QueryVariantSource::Phonetic,
                [CorrectionTransformationType::Phonetic, true] => QueryVariantSource::KeyboardPhonetic,
                [CorrectionTransformationType::Split, false] => QueryVariantSource::Split,
                [CorrectionTransformationType::Split, true] => QueryVariantSource::KeyboardSplit,
                [CorrectionTransformationType::Merge, false] => QueryVariantSource::Merge,
                [CorrectionTransformationType::Merge, true] => QueryVariantSource::KeyboardMerge,
            };
            $variants = $variants->with(new QueryVariant(
                query: $correction->correctedQuery,
                locale: $correction->locale,
                tokens: $correction->tokens,
                source: $source,
                priority: $this->policy->priority($source),
                fingerprint: $this->fingerprint(
                    $source,
                    $correction->correctedQuery,
                    $correction->locale,
                    $parent->fingerprint,
                    $correction->fingerprint,
                ),
                parentFingerprint: $parent->fingerprint,
                keyboardCorrection: $parent->keyboardCorrection,
                spellingCorrection: $parent->spellingCorrection,
                advancedCorrection: $correction,
            ));

            if ($variants->isFull()) {
                return $variants;
            }
        }

        return $variants;
    }

    /**
     * @param  list<QueryVariant>  $retainedParents
     */
    private function addSpelling(
        QueryVariantCollection $variants,
        QueryVariant $parent,
        QueryVariantSource $source,
        array &$retainedParents,
    ): QueryVariantCollection {
        if ($variants->isFull() || $this->spelling === null) {
            return $variants;
        }

        foreach ($this->spelling->correct($parent) as $correction) {
            $candidate = new QueryVariant(
                query: $correction->correctedQuery,
                locale: $correction->locale,
                tokens: $correction->tokens,
                source: $source,
                priority: $this->policy->priority($source),
                fingerprint: $this->fingerprint($source, $correction->correctedQuery, $correction->locale, $parent->fingerprint, $correction->fingerprint),
                parentFingerprint: $parent->fingerprint,
                keyboardCorrection: $parent->keyboardCorrection,
                spellingCorrection: $correction,
            );
            $variants = $variants->with($candidate);
            if ($this->retains($variants, $candidate->fingerprint)) {
                $retainedParents[] = $candidate;
            }

            if ($variants->isFull()) {
                return $variants;
            }
        }

        return $variants;
    }

    private function retains(QueryVariantCollection $variants, string $fingerprint): bool
    {
        foreach ($variants as $variant) {
            if ($variant->fingerprint === $fingerprint) {
                return true;
            }
        }

        return false;
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
                advancedCorrection: $parent->advancedCorrection,
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
