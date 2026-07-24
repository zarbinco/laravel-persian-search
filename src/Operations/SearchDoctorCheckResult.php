<?php

namespace Zarbinco\PersianSearch\Operations;

use InvalidArgumentException;
use JsonSerializable;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchDoctorCheckResult implements JsonSerializable
{
    public function __construct(
        public string $key,
        public SearchDoctorCheckStatus $status,
        public string $message,
    ) {
        if (! CanonicalConfigurationName::isValid($this->key) || trim($this->message) === '') {
            throw new InvalidArgumentException('Doctor result keys and messages must be safe non-empty strings.');
        }
    }

    /** @return array{key: string, status: string, message: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'status' => $this->status->value, 'message' => $this->message];
    }

    /** @return array{key: string, status: string, message: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
