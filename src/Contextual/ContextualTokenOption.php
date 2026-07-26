<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

final readonly class ContextualTokenOption
{
    public function __construct(
        public int $tokenIndex,
        public string $original,
        public string $corrected,
        public string $locale,
        public ContextualCandidateSource $source,
        public int $lexicalCost,
        public int $originalDocumentFrequency,
        public int $candidateDocumentFrequency,
        public int $originalCorpusScore,
        public int $candidateCorpusScore,
        public string $rule,
    ) {}
}
