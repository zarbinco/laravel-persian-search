<?php

namespace Zarbinco\PersianSearch\Ranking;

use Zarbinco\PersianSearch\Candidates\SearchDocumentField;

final readonly class SearchDocumentTokenMap
{
    /**
     * @param  list<string>  $title
     * @param  list<string>  $keywords
     * @param  list<string>  $excerpt
     * @param  list<string>  $content
     */
    public function __construct(
        public array $title,
        public array $keywords,
        public array $excerpt,
        public array $content,
    ) {}

    /** @return list<string> */
    public function forField(SearchDocumentField $field): array
    {
        return match ($field) {
            SearchDocumentField::Title => $this->title,
            SearchDocumentField::Keywords => $this->keywords,
            SearchDocumentField::Excerpt => $this->excerpt,
            SearchDocumentField::Content => $this->content,
        };
    }
}
