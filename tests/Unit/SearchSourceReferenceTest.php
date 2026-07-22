<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchSourceReferenceException;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final class SearchSourceReferenceTest extends TestCase
{
    public function test_required_fields_are_validated(): void
    {
        foreach ([[' ', 'page'], ['page:one', ' ']] as [$key, $type]) {
            try {
                new SearchSourceReference($key, $type, null);
                $this->fail('Expected invalid reference exception.');
            } catch (InvalidSearchSourceReferenceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_source_ids_are_canonical_and_lossless(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $ulid = '01J0ABCDEFGHJKMNPQRSTVWXYZ';

        $this->assertSame('123', (new SearchSourceReference('one', 'type', 123))->sourceId);
        $this->assertSame('00123', (new SearchSourceReference('two', 'type', '00123'))->sourceId);
        $this->assertSame($uuid, (new SearchSourceReference('three', 'type', $uuid))->sourceId);
        $this->assertSame($ulid, (new SearchSourceReference('four', 'type', $ulid))->sourceId);
        $this->assertNull((new SearchSourceReference('five', 'type', null))->sourceId);
    }

    public function test_fingerprint_and_serialization_are_deterministic_and_source_only(): void
    {
        $first = new SearchSourceReference('page:about', 'page', null);
        $same = new SearchSourceReference('page:about', 'page', null);
        $different = new SearchSourceReference('page:contact', 'page', null);

        $this->assertSame($first->fingerprint(), $same->fingerprint());
        $this->assertNotSame($first->fingerprint(), $different->fingerprint());
        $this->assertNotSame(
            (new SearchSourceReference('page:about', 'page', null))->fingerprint(),
            (new SearchSourceReference('page:about', 'page', ''))->fingerprint(),
        );
        $this->assertSame($first->toArray(), $same->toArray());
        $this->assertArrayNotHasKey('locale', $first->toArray());
        $this->assertArrayNotHasKey('partition', $first->toArray());
    }
}
