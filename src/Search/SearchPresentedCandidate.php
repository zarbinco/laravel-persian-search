<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidate;

final readonly class SearchPresentedCandidate
{
    public function __construct(
        public SearchRankedCandidate $matchedCandidate,
        public SearchDocumentRecord $presentedDocument,
        public SearchLocaleBridgeMetadata $bridge,
    ) {
        $matched = $this->matchedCandidate->candidate->document;
        $matchedId = (string) $matched->getKey();
        $presentedId = (string) $this->presentedDocument->getKey();

        if (! $matched->exists
            || $matched->getKey() === null
            || ! $this->presentedDocument->exists
            || $this->presentedDocument->getKey() === null
            || $matched->source_key !== $this->presentedDocument->source_key
            || $matched->source_type !== $this->presentedDocument->source_type
            || $matched->source_id !== $this->presentedDocument->source_id
            || $matched->partition !== $this->presentedDocument->partition
            || $matched->locale !== $this->bridge->matchedLocale
            || $this->presentedDocument->locale !== $this->bridge->presentedLocale
            || $this->matchedCandidate->rank->variant->locale !== $this->bridge->matchedLocale) {
            throw new InvalidArgumentException('Presented search candidate identity or bridge metadata is inconsistent.');
        }

        $statusIsValid = match ($this->bridge->status) {
            SearchLocaleBridgeStatus::NotRequired,
            SearchLocaleBridgeStatus::CounterpartMissing,
            SearchLocaleBridgeStatus::Disabled => $presentedId === $matchedId,
            SearchLocaleBridgeStatus::Bridged => $presentedId !== $matchedId
                && $this->presentedDocument->is_active === true,
        };

        if (! $statusIsValid) {
            throw new InvalidArgumentException('Presented search candidate status is inconsistent.');
        }
    }

    public function identity(): string
    {
        return (string) $this->presentedDocument->getKey();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'presented_document_id' => $this->identity(),
            'matched_document_id' => $this->matchedCandidate->candidate->identity(),
            'rank' => $this->matchedCandidate->rank->toArray(),
            'bridge' => $this->bridge->toArray(),
        ];
    }
}
