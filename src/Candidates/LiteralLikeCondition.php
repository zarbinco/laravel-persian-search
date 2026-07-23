<?php

namespace Zarbinco\PersianSearch\Candidates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use LogicException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final class LiteralLikeCondition
{
    /**
     * @param  Builder<SearchDocumentRecord>  $query
     */
    public function orWhereContains(Builder $query, SearchDocumentField $field, string $term): void
    {
        $query->orWhereRaw(
            $this->clause($query->getQuery()->getGrammar(), $field),
            [LiteralLikePattern::contains($term)->value],
        );
    }

    /** @return literal-string */
    public function clause(Grammar $grammar, SearchDocumentField $field): string
    {
        return match ($grammar->wrap($field->value)) {
            '"normalized_title"' => '"normalized_title" LIKE ? ESCAPE \'!\'',
            '"normalized_keywords"' => '"normalized_keywords" LIKE ? ESCAPE \'!\'',
            '"normalized_excerpt"' => '"normalized_excerpt" LIKE ? ESCAPE \'!\'',
            '"normalized_content"' => '"normalized_content" LIKE ? ESCAPE \'!\'',
            '`normalized_title`' => '`normalized_title` LIKE ? ESCAPE \'!\'',
            '`normalized_keywords`' => '`normalized_keywords` LIKE ? ESCAPE \'!\'',
            '`normalized_excerpt`' => '`normalized_excerpt` LIKE ? ESCAPE \'!\'',
            '`normalized_content`' => '`normalized_content` LIKE ? ESCAPE \'!\'',
            default => throw new LogicException('The database grammar produced an unsupported search-document column quotation.'),
        };
    }
}
