<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePlan;
use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;

final class SearchCandidatePlanTest extends TestCase
{
    public function test_plan_preserves_fixed_fields_filters_terms_and_limit(): void
    {
        $plan = new SearchCandidatePlan(
            $this->variant(),
            ['orange juice', 'orange', 'orange'],
            SearchDocumentField::cases(),
            'public',
            ['product', 'page', 'product'],
            100,
        );

        $this->assertSame(['orange juice', 'orange'], $plan->terms);
        $this->assertSame([
            SearchDocumentField::Title,
            SearchDocumentField::Keywords,
            SearchDocumentField::Excerpt,
            SearchDocumentField::Content,
        ], $plan->fields);
        $this->assertSame(['product', 'page'], $plan->sourceTypes);
        $this->assertSame('public', $plan->partition);
        $this->assertSame(100, $plan->limit);
        $this->assertSame('normalized_title', $plan->toArray()['fields'][0]);
    }

    public function test_plan_rejects_missing_terms_non_fixed_fields_and_invalid_limit(): void
    {
        foreach ([
            [[], SearchDocumentField::cases(), 1],
            [['orange'], [SearchDocumentField::Title], 1],
            [['orange'], SearchDocumentField::cases(), 0],
        ] as [$terms, $fields, $limit]) {
            try {
                new SearchCandidatePlan($this->variant(), $terms, $fields, null, [], $limit);
                $this->fail('Expected invalid candidate plan.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function variant(): QueryVariant
    {
        return new QueryVariant(
            'orange juice',
            'en',
            ['orange', 'juice'],
            QueryVariantSource::Original,
            1000,
            'original',
        );
    }
}
