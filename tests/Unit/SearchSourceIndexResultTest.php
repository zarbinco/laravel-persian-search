<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Indexing\SearchSourceIndexResult;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final class SearchSourceIndexResultTest extends TestCase
{
    public function test_searchsourceindexresult_exposes_exact_counts_and_helpers(): void
    {
        $result = new SearchSourceIndexResult(
            new SearchSourceReference('page:about', 'page', null),
            incoming: 4,
            created: 1,
            updated: 1,
            unchanged: 2,
            deleted: 3,
            final: 4,
        );

        $this->assertSame(5, $result->changed());
        $this->assertFalse($result->isNoOp());
        $this->assertSame(4, $result->toArray()['incoming']);
        $this->assertSame('page:about', $result->toArray()['reference']['source_key']);

        $noOp = new SearchSourceIndexResult(
            new SearchSourceReference('page:about', 'page', null),
            incoming: 2,
            created: 0,
            updated: 0,
            unchanged: 2,
            deleted: 0,
            final: 2,
        );
        $this->assertTrue($noOp->isNoOp());
    }

    public function test_searchsourceindexresult_rejects_invalid_invariants(): void
    {
        foreach ([
            [-1, 0, 0, 0, 0, -1],
            [2, 1, 0, 0, 0, 2],
            [1, 1, 0, 0, 0, 2],
        ] as [$incoming, $created, $updated, $unchanged, $deleted, $final]) {
            try {
                new SearchSourceIndexResult(
                    new SearchSourceReference('one', 'type', '1'),
                    $incoming,
                    $created,
                    $updated,
                    $unchanged,
                    $deleted,
                    $final,
                );
                $this->fail('Expected invalid result invariant.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
