<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchResultConfigurationException;
use Zarbinco\PersianSearch\Search\SearchResultPolicyFactory;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchResultPolicyTest extends TestCase
{
    public function test_defaults_are_typed_and_serializable(): void
    {
        $this->assertSame([
            'default_per_page' => 15,
            'maximum_per_page' => 100,
            'default_preview_limit' => 8,
            'maximum_preview_limit' => 50,
            'default_preview_per_type' => 2,
            'maximum_preview_per_type' => 10,
            'maximum_groups' => 50,
        ], app(SearchResultPolicyFactory::class)->make()->toArray());
    }

    public function test_default_cannot_exceed_its_maximum(): void
    {
        config()->set('persian-search.results.default_preview_limit', 11);
        config()->set('persian-search.results.maximum_preview_limit', 10);

        $this->expectException(InvalidSearchResultConfigurationException::class);
        app(SearchResultPolicyFactory::class)->make();
    }
}
