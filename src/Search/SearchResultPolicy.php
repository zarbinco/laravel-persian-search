<?php

namespace Zarbinco\PersianSearch\Search;

final readonly class SearchResultPolicy
{
    public function __construct(
        public int $defaultPerPage,
        public int $maximumPerPage,
        public int $defaultPreviewLimit,
        public int $maximumPreviewLimit,
        public int $defaultPreviewPerType,
        public int $maximumPreviewPerType,
        public int $maximumGroups,
    ) {}

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'default_per_page' => $this->defaultPerPage,
            'maximum_per_page' => $this->maximumPerPage,
            'default_preview_limit' => $this->defaultPreviewLimit,
            'maximum_preview_limit' => $this->maximumPreviewLimit,
            'default_preview_per_type' => $this->defaultPreviewPerType,
            'maximum_preview_per_type' => $this->maximumPreviewPerType,
            'maximum_groups' => $this->maximumGroups,
        ];
    }
}
