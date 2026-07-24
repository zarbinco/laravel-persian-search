<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianSearch\Dependencies\SearchDependencyPolicy;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyResolverRegistry;
use Zarbinco\PersianSearch\Operations\SearchDoctorCheckStatus;
use Zarbinco\PersianSearch\Operations\SearchDoctorService;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchDoctorIsolationTest extends TestCase
{
    public function test_malformed_dependency_configuration_remains_a_local_check_failure(): void
    {
        config()->set('persian-search.dependencies', 'malformed-secret-value');
        app()->forgetInstance(SearchDependencyPolicy::class);
        app()->forgetInstance(SearchDependencyResolverRegistry::class);

        $results = [];
        foreach (app(SearchDoctorService::class)->run()->results as $result) {
            $results[$result->key] = $result->status;
        }

        $this->assertSame(SearchDoctorCheckStatus::Failed, $results['configuration.policies']);
        $this->assertSame(SearchDoctorCheckStatus::Failed, $results['extensions.dependencies']);
        $this->assertArrayHasKey('queue.configuration', $results);
    }
}
