<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Exceptions\DuplicateSearchLocaleCounterpartException;
use Zarbinco\PersianSearch\Search\SearchLocaleCounterpartIdentity;

final class SearchLocaleCounterpartIdentityTest extends TestCase
{
    public function test_length_prefixed_lookup_identity_is_deterministic_and_delimiter_safe(): void
    {
        $first = SearchLocaleCounterpartIdentity::key("a\0b", 'c');
        $second = SearchLocaleCounterpartIdentity::key('a', "b\0c");

        $this->assertSame($first, SearchLocaleCounterpartIdentity::key("a\0b", 'c'));
        $this->assertNotSame($first, $second);
    }

    public function test_duplicate_counterpart_message_uses_the_same_safe_description(): void
    {
        $sourceKey = "unsafe\n\u{202E}";
        $message = DuplicateSearchLocaleCounterpartException::forIdentity('public', $sourceKey, 'en')->getMessage();

        $this->assertStringNotContainsString($sourceKey, $message);
        $this->assertStringContainsString(hash('sha256', $sourceKey), $message);
        $this->assertStringContainsString('length ['.strlen($sourceKey).']', $message);
    }
}
