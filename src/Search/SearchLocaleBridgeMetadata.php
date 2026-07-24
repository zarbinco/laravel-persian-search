<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use JsonSerializable;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchLocaleBridgeMetadata implements JsonSerializable
{
    public bool $wasBridged;

    public function __construct(
        public SearchLocaleBridgeStatus $status,
        public string $requestedLocale,
        public string $matchedLocale,
        public string $presentedLocale,
    ) {
        foreach ([$this->requestedLocale, $this->matchedLocale, $this->presentedLocale] as $locale) {
            if (! CanonicalConfigurationName::isValid($locale)) {
                throw new InvalidArgumentException('Search locale bridge metadata contains an invalid locale.');
            }
        }

        $this->wasBridged = $this->status === SearchLocaleBridgeStatus::Bridged;
        $valid = match ($this->status) {
            SearchLocaleBridgeStatus::NotRequired => $this->matchedLocale === $this->requestedLocale
                && $this->presentedLocale === $this->matchedLocale,
            SearchLocaleBridgeStatus::Bridged => $this->matchedLocale !== $this->requestedLocale
                && $this->presentedLocale === $this->requestedLocale,
            SearchLocaleBridgeStatus::CounterpartMissing,
            SearchLocaleBridgeStatus::Disabled => $this->matchedLocale !== $this->requestedLocale
                && $this->presentedLocale === $this->matchedLocale,
        };

        if (! $valid) {
            throw new InvalidArgumentException('Search locale bridge metadata state is inconsistent.');
        }
    }

    /** @return array<string, bool|string> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'requested_locale' => $this->requestedLocale,
            'matched_locale' => $this->matchedLocale,
            'presented_locale' => $this->presentedLocale,
            'was_bridged' => $this->wasBridged,
        ];
    }

    /** @return array<string, bool|string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
