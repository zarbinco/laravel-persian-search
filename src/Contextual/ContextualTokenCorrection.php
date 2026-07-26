<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ContextualTokenCorrection implements JsonSerializable
{
    public function __construct(
        public int $tokenIndex,
        public string $original,
        public string $corrected,
        public ContextualCandidateSource $source,
        public int $lexicalCost,
        public int $originalDocumentFrequency,
        public int $candidateDocumentFrequency,
        public string $rule,
    ) {
        if ($this->tokenIndex < 0 || $this->original === '' || $this->corrected === ''
            || $this->original === $this->corrected || $this->lexicalCost < 1
            || $this->originalDocumentFrequency < 1 || $this->candidateDocumentFrequency < 1
            || trim($this->rule) === '') {
            throw new InvalidArgumentException('Contextual token correction metadata is invalid.');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'token_index' => $this->tokenIndex,
            'original' => $this->original,
            'corrected' => $this->corrected,
            'source' => $this->source->value,
            'lexical_cost' => $this->lexicalCost,
            'original_document_frequency' => $this->originalDocumentFrequency,
            'candidate_document_frequency' => $this->candidateDocumentFrequency,
            'rule' => $this->rule,
        ];
    }

    /** @return array<string, int|string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
