<?php

namespace Zarbinco\PersianSearch\Query;

use Zarbinco\PersianSearch\Contracts\SynonymExpander;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final readonly class TokenAwareSynonymExpander implements SynonymExpander
{
    public function __construct(
        private SynonymDictionary $dictionary,
        private SearchTextPipeline $pipeline,
    ) {}

    public function expand(QueryVariant $variant): iterable
    {
        if (! $this->dictionary->enabled) {
            return;
        }

        $seenCandidates = [];
        $seenSemanticQueries = [];

        foreach ($this->dictionary->forLocale($variant->locale) as $rule) {
            $sourceLength = count($rule->sourceTokens);
            $lastStart = count($variant->tokens) - $sourceLength;

            for ($start = 0; $start <= $lastStart; $start++) {
                if (array_slice($variant->tokens, $start, $sourceLength) !== $rule->sourceTokens) {
                    continue;
                }

                $tokens = array_merge(
                    array_slice($variant->tokens, 0, $start),
                    $rule->replacementTokens,
                    array_slice($variant->tokens, $start + $sourceLength),
                );
                $candidateKey = hash('sha256', $variant->locale."\0".implode("\0", $tokens));

                if (isset($seenCandidates[$candidateKey])) {
                    continue;
                }

                $seenCandidates[$candidateKey] = true;
                $prepared = $this->pipeline->prepare(implode(' ', $tokens), $variant->locale);

                if ($prepared->normalized === '' || $prepared->normalized === $variant->query || $prepared->tokens === []) {
                    continue;
                }

                $semanticKey = hash('sha256', $prepared->locale."\0".$prepared->normalized);

                if (isset($seenSemanticQueries[$semanticKey])) {
                    continue;
                }

                $seenSemanticQueries[$semanticKey] = true;

                $fingerprint = hash('sha256', implode("\0", [
                    $variant->fingerprint,
                    $rule->source,
                    $rule->replacement,
                    (string) $start,
                    $prepared->normalized,
                ]));
                yield new SynonymExpansion(
                    sourceTerm: $rule->source,
                    replacementTerm: $rule->replacement,
                    query: $prepared->normalized,
                    tokens: $prepared->tokens,
                    locale: $prepared->locale,
                    tokenStart: $start,
                    tokenLength: $sourceLength,
                    fingerprint: $fingerprint,
                );
            }
        }

    }
}
