<?php

namespace Zarbinco\PersianSearch\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Testing\PendingCommand;
use LogicException;
use Orchestra\Testbench\TestCase as Orchestra;
use Zarbinco\PersianCore\PersianCoreServiceProvider;
use Zarbinco\PersianSearch\Contracts\SearchSourceEnumerator;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocatorFactory;
use Zarbinco\PersianSearch\Operations\SearchOperationsPolicy;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumerationContext;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumeratorRegistry;
use Zarbinco\PersianSearch\PersianSearchServiceProvider;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            PersianCoreServiceProvider::class,
            PersianSearchServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /** @param class-string<Model> $modelClass */
    protected function operationalReindex(string $modelClass): PendingCommand
    {
        TestingModelSearchSourceEnumerator::$modelClass = $modelClass;
        config()->set('persian-search.operations.enumerators', [TestingModelSearchSourceEnumerator::class]);
        app()->forgetInstance(SearchOperationsPolicy::class);
        app()->forgetInstance(SearchSourceEnumeratorRegistry::class);

        $command = $this->artisan('persian-search:reindex', [
            '--enumerator' => ['testing-model'],
            '--sync' => true,
            '--force' => true,
        ]);
        if (! $command instanceof PendingCommand) {
            throw new LogicException('The test reindex command did not initialize.');
        }

        return $command;
    }
}

final class TestingModelSearchSourceEnumerator implements SearchSourceEnumerator
{
    /** @var class-string<Model> */
    public static string $modelClass;

    public function __construct(
        private readonly SearchSourceLocatorFactory $locators,
        private readonly SearchDocumentProviderRegistry $providers,
    ) {}

    public function key(): string
    {
        return 'testing-model';
    }

    public function providerKey(): string
    {
        $class = self::$modelClass;

        return $this->providers->keyFor($this->providers->resolve(new $class));
    }

    public function sourceModel(): string
    {
        return self::$modelClass;
    }

    public function enumerate(SearchSourceEnumerationContext $context): iterable
    {
        $class = self::$modelClass;
        $model = new $class;
        $query = $model->newQuery()->orderBy($model->getQualifiedKeyName());
        if ((bool) config('persian-search.index.include_soft_deleted', false)
            && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $withTrashed = [$query, 'withTrashed'];
            if (! is_callable($withTrashed)) {
                throw new LogicException('Soft-deleting test query does not support withTrashed().');
            }
            $withTrashed();
        }

        foreach ($query->lazyById($context->chunkSize) as $source) {
            yield $this->locators->forModel($source, $this->providerKey());
        }
    }
}
