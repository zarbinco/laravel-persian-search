<?php

namespace Zarbinco\PersianSearch\Providers;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchSourceReferenceException;

final readonly class SearchSourceReference
{
    public string $sourceKey;

    public string $sourceType;

    public ?string $sourceId;

    public function __construct(string $sourceKey, string $sourceType, mixed $sourceId)
    {
        $this->sourceKey = trim($sourceKey);
        $this->sourceType = trim($sourceType);

        if ($this->sourceKey === '') {
            throw InvalidSearchSourceReferenceException::empty('key');
        }

        if ($this->sourceType === '') {
            throw InvalidSearchSourceReferenceException::empty('type');
        }

        if (! is_int($sourceId) && ! is_string($sourceId) && $sourceId !== null) {
            throw InvalidSearchSourceReferenceException::invalidId($sourceId);
        }

        $this->sourceId = is_int($sourceId) ? (string) $sourceId : $sourceId;
    }

    public function fingerprint(): string
    {
        $id = $this->sourceId === null ? 'null' : 'string:'.strlen($this->sourceId).':'.$this->sourceId;

        return hash('sha256', implode('|', [
            strlen($this->sourceKey).':'.$this->sourceKey,
            strlen($this->sourceType).':'.$this->sourceType,
            $id,
        ]));
    }

    /** @return array{source_key: string, source_type: string, source_id: string|null, fingerprint: string} */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'fingerprint' => $this->fingerprint(),
        ];
    }
}
