<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\PersianSearch\Contracts\SearchDependencyResolver;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyContext;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyObserverRegistrar;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyOperation;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyPolicy;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyPolicyFactory;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyPreparedChange;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyResolverRegistration;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyResolverRegistry;
use Zarbinco\PersianSearch\Dependencies\SearchDependencySnapshotFactory;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyState;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyTargetCollection;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyTargetResolver;
use Zarbinco\PersianSearch\Dependencies\WeakMapSearchDependencyPendingState;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDependencyConfigurationException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDependencyResolverException;
use Zarbinco\PersianSearch\Exceptions\SearchDependencyFanoutExceededException;
use Zarbinco\PersianSearch\Exceptions\SearchDependencyTargetConflictException;
use Zarbinco\PersianSearch\Jobs\SynchronizeEloquentSearchSourceJob;
use Zarbinco\PersianSearch\Lifecycle\EloquentSearchSourceLocator;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronization;
use Zarbinco\PersianSearch\Lifecycle\SearchQueuePolicy;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocator;
use Zarbinco\PersianSearch\PersianSearchServiceProvider;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchDependencyInfrastructureTest extends TestCase
{
    #[DataProvider('focusedComponentNames')]
    public function test_focused_component_contracts_are_available(string $component): void
    {
        $this->assertTrue(class_exists("Zarbinco\\PersianSearch\\Dependencies\\{$component}"));
    }

    /** @return array<string, array{string}> */
    public static function focusedComponentNames(): array
    {
        return [
            'SearchDependencyPolicy' => ['SearchDependencyPolicy'],
            'SearchDependencyResolver' => ['SearchDependencyResolverRegistry'],
            'SearchDependencyRegistry' => ['SearchDependencyResolverRegistry'],
            'SearchDependencyContext' => ['SearchDependencyContext'],
            'SearchDependencySnapshot' => ['SearchDependencySnapshotFactory'],
            'SearchDependencyTarget' => ['SearchDependencyTargetCollection'],
            'SearchDependencyObserver' => ['SearchDependencyObserver'],
        ];
    }

    public function test_search_dependency_policy_is_strict_and_enforces_the_hard_ceiling(): void
    {
        config()->set('persian-search.dependencies.enabled', true);
        config()->set('persian-search.dependencies.maximum_sources_per_event', 20000);

        $policy = app(SearchDependencyPolicyFactory::class)->make();

        $this->assertTrue($policy->enabled);
        $this->assertSame(20000, $policy->maximumSourcesPerEvent);

        config()->set('persian-search.dependencies.maximum_sources_per_event', 20001);
        $this->expectException(InvalidSearchDependencyConfigurationException::class);
        app(SearchDependencyPolicyFactory::class)->make();
    }

    public function test_search_dependency_snapshot_preserves_old_raw_foreign_key_values(): void
    {
        $model = new DependencyInfrastructureModel;
        $model->setConnection('testing');
        $model->setRawAttributes(['id' => 7, 'group_id' => 1, 'name' => 'old'], true);
        $model->exists = true;
        $model->setRelation('loaded', new \stdClass);
        $model->setAttribute('group_id', 2);

        $snapshot = (new SearchDependencySnapshotFactory)->beforeUpdate($model);

        $this->assertNotSame($model, $snapshot);
        $this->assertTrue($snapshot->exists);
        $this->assertSame(1, $snapshot->getAttribute('group_id'));
        $this->assertSame([], $snapshot->getRelations());
        $this->assertSame('testing', $snapshot->getConnection()->getName());
    }

    public function test_search_dependency_context_rejects_semantically_invalid_operation_state_combinations(): void
    {
        $snapshot = $this->snapshot();

        $this->expectException(InvalidArgumentException::class);
        new SearchDependencyContext(
            $snapshot,
            SearchDependencyOperation::Created,
            SearchDependencyState::Before,
            'testing',
        );
    }

    public function test_context_accepts_canonical_update_changed_attributes(): void
    {
        $context = new SearchDependencyContext(
            $this->snapshot(),
            SearchDependencyOperation::Updated,
            SearchDependencyState::Before,
            'testing',
            ['group_id', 'name'],
        );

        $this->assertSame(['group_id', 'name'], $context->changedAttributes);
    }

    public function test_search_dependency_target_collection_deduplicates_and_sorts_deterministically(): void
    {
        $two = $this->locator('2');
        $one = $this->locator('1');
        $targets = new SearchDependencyTargetCollection([$two, $one, $two], 2);
        $actual = iterator_to_array($targets);
        $fingerprints = array_map(static fn (SearchSourceLocator $target): string => $target->fingerprint(), $actual);
        $sorted = $fingerprints;
        sort($sorted, SORT_STRING);

        $this->assertCount(2, $targets);
        $this->assertSame($sorted, $fingerprints);
    }

    public function test_target_collection_fails_atomically_when_fanout_is_exceeded(): void
    {
        $this->expectException(SearchDependencyFanoutExceededException::class);
        new SearchDependencyTargetCollection([$this->locator('1'), $this->locator('2')], 1);
    }

    public function test_search_dependency_observer_pending_state_is_identity_scoped_and_take_clears_it(): void
    {
        $model = $this->snapshot();
        $targets = new SearchDependencyTargetCollection([], 1);
        $change = new SearchDependencyPreparedChange(
            SearchDependencyOperation::Updated,
            'testing',
            $targets,
            ['name'],
        );
        $pending = new WeakMapSearchDependencyPendingState;

        $pending->put($model, $change);

        $this->assertSame($change, $pending->take($model));
        $this->assertNull($pending->take($model));
        $this->assertNull($pending->take($this->snapshot()));
    }

    public function test_search_dependency_resolver_and_search_dependency_registry_are_deterministic(): void
    {
        $registry = new SearchDependencyResolverRegistry(
            app(),
            new SearchDependencyPolicy(true, 10, [
                ZDependencyInfrastructureResolver::class,
                ADependencyInfrastructureResolver::class,
            ]),
        );

        $this->assertSame(
            ['a_dependency', 'z_dependency'],
            array_map(static fn (SearchDependencyResolver $resolver): string => $resolver->key(), $registry->all()),
        );
        $this->assertSame([DependencyInfrastructureModel::class], $registry->dependencyModels());
    }

    public function test_before_after_union_deduplicates_the_same_foreign_key_source(): void
    {
        $before = new SearchDependencyTargetCollection([$this->locator('1')], 2);
        $after = new SearchDependencyTargetCollection([$this->locator('1'), $this->locator('2')], 2);

        $this->assertCount(2, SearchDependencyTargetCollection::merge($before, $after, 2));
    }

    public function test_provider_aware_unique_identity_separates_providers_for_the_same_source(): void
    {
        $locator = new EloquentSearchSourceLocator(DependencySourceModel::class, 'testing', 'id', '7');
        $reference = new SearchSourceReference('source:7', 'source', '7');
        $policy = new SearchQueuePolicy(null, null, 3, [1], 60, 300);
        $first = new SynchronizeEloquentSearchSourceJob(
            new SearchLifecycleSynchronization($locator, $reference, 'provider-a'),
            $policy,
        );
        $second = new SynchronizeEloquentSearchSourceJob(
            new SearchLifecycleSynchronization($locator, $reference, 'provider-b'),
            $policy,
        );

        $this->assertNotSame($first->uniqueId(), $second->uniqueId());
    }

    public function test_fallback_conflict_is_rejected_instead_of_using_last_write_wins(): void
    {
        $first = $this->locator('7');
        $conflict = new SearchSourceLocator(
            $first->source,
            $first->providerKey,
            new SearchSourceReference('conflicting:7', 'other-source', '7'),
        );

        $this->expectException(SearchDependencyTargetConflictException::class);
        new SearchDependencyTargetCollection([$first, $conflict], 2);
    }

    public function test_fallback_conflict_during_before_after_merge_is_rejected(): void
    {
        $first = $this->locator('7');
        $conflict = new SearchSourceLocator(
            $first->source,
            $first->providerKey,
            new SearchSourceReference('conflicting:7', 'other-source', '7'),
        );
        $before = new SearchDependencyTargetCollection([$first], 2);
        $after = new SearchDependencyTargetCollection([$conflict], 2);

        $this->expectException(SearchDependencyTargetConflictException::class);
        SearchDependencyTargetCollection::merge($before, $after, 2);
    }

    public function test_fanout_stops_generator_at_first_excess_unique_target(): void
    {
        $consumed = 0;
        $targets = (function () use (&$consumed): iterable {
            $consumed++;
            yield $this->locator('1');
            $consumed++;
            yield $this->locator('1');
            $consumed++;
            yield $this->locator('2');
            $consumed++;
            yield $this->locator('3');
        })();

        try {
            new SearchDependencyTargetCollection($targets, 1);
            $this->fail('Expected dependency fan-out overflow.');
        } catch (SearchDependencyFanoutExceededException) {
            $this->assertSame(3, $consumed);
        }
    }

    public function test_provider_aware_targets_keep_same_source_for_different_providers(): void
    {
        $first = $this->locator('7');
        $second = new SearchSourceLocator(
            $first->source,
            'second-provider',
            $first->fallbackReference,
        );

        $this->assertCount(2, new SearchDependencyTargetCollection([$first, $second], 2));
    }

    public function test_resolver_isolation_gives_each_resolver_an_unmodified_snapshot(): void
    {
        MutatingDependencyResolver::$snapshot = null;
        InspectingDependencyResolver::$snapshot = null;
        InspectingDependencyResolver::$attributes = [];
        $registry = new SearchDependencyResolverRegistry(
            app(),
            new SearchDependencyPolicy(true, 10, [
                MutatingDependencyResolver::class,
                InspectingDependencyResolver::class,
            ]),
        );
        $resolver = new SearchDependencyTargetResolver(
            $registry,
            new SearchDependencyPolicy(true, 10),
            new SearchDependencySnapshotFactory,
        );
        $live = $this->snapshot();

        $resolver->resolve(
            $live,
            SearchDependencyOperation::Updated,
            SearchDependencyState::Before,
            ['name'],
        );

        $mutatingSnapshot = MutatingDependencyResolver::$snapshot;
        $inspectingSnapshot = InspectingDependencyResolver::$snapshot;
        $this->assertInstanceOf(Model::class, $mutatingSnapshot);
        $this->assertInstanceOf(Model::class, $inspectingSnapshot);
        $this->assertNotSame($mutatingSnapshot, $inspectingSnapshot);
        $this->assertSame(['id' => 7, 'group_id' => 1, 'name' => 'old'], InspectingDependencyResolver::$attributes);
        $this->assertSame('old', $live->getAttribute('name'));
        $this->assertSame('testing', $inspectingSnapshot->getConnectionName());
        $this->assertSame('dependency_infrastructure_models', $inspectingSnapshot->getTable());
        $this->assertSame([], $inspectingSnapshot->getRelations());
    }

    public function test_unstable_resolver_metadata_is_rejected_and_stable_metadata_is_cached(): void
    {
        UnstableDependencyModelResolver::$calls = 0;
        $unstable = new SearchDependencyResolverRegistry(
            app(),
            new SearchDependencyPolicy(true, 10, [UnstableDependencyModelResolver::class]),
        );

        $this->expectException(InvalidSearchDependencyResolverException::class);
        $unstable->all();
    }

    public function test_unstable_resolver_key_is_rejected(): void
    {
        UnstableDependencyKeyResolver::$calls = 0;
        $registry = new SearchDependencyResolverRegistry(
            app(),
            new SearchDependencyPolicy(true, 10, [UnstableDependencyKeyResolver::class]),
        );

        $this->expectException(InvalidSearchDependencyResolverException::class);
        $registry->registrations();
    }

    public function test_disabled_policy_does_not_instantiate_application_resolvers(): void
    {
        ConstructedDependencyResolver::$constructions = 0;
        $policy = new SearchDependencyPolicy(false, 10, [ConstructedDependencyResolver::class]);
        $registry = new SearchDependencyResolverRegistry(app(), $policy);
        $registrar = new SearchDependencyObserverRegistrar($registry, app(), $policy);

        $registrar->register();

        $this->assertSame(0, ConstructedDependencyResolver::$constructions);
    }

    public function test_dependency_boot_validation_rejects_a_malformed_top_level_section(): void
    {
        config()->set('persian-search.dependencies', 'invalid');
        app()->forgetInstance(SearchDependencyPolicy::class);
        app()->forgetInstance(SearchDependencyResolverRegistry::class);
        app()->forgetInstance(SearchDependencyObserverRegistrar::class);

        $this->expectException(InvalidSearchDependencyConfigurationException::class);
        (new PersianSearchServiceProvider(app()))->boot();
    }

    public function test_resolver_metadata_methods_are_called_exactly_twice_during_initialization(): void
    {
        CountingDependencyResolver::$keyCalls = 0;
        CountingDependencyResolver::$modelCalls = 0;
        $registry = new SearchDependencyResolverRegistry(
            app(),
            new SearchDependencyPolicy(true, 10, [CountingDependencyResolver::class]),
        );

        $registry->all();
        $registry->forModel($this->snapshot());
        $registry->dependencyModels();

        $this->assertSame(2, CountingDependencyResolver::$keyCalls);
        $this->assertSame(2, CountingDependencyResolver::$modelCalls);
    }

    public function test_snapshot_copy_preserves_runtime_key_metadata_and_isolation(): void
    {
        $model = $this->snapshot();
        $model->setKeyName('external_id');
        $model->setKeyType('string');
        $model->setIncrementing(false);
        $model->setTable('runtime_dependencies');
        $model->setRawAttributes(['external_id' => '0007', 'name' => 'old'], true);
        $copy = (new SearchDependencySnapshotFactory)->copy($model);
        $copy->setAttribute('name', 'mutated');

        $this->assertSame('external_id', $copy->getKeyName());
        $this->assertSame('string', $copy->getKeyType());
        $this->assertFalse($copy->getIncrementing());
        $this->assertSame('runtime_dependencies', $copy->getTable());
        $this->assertSame('0007', $copy->getKey());
        $this->assertSame('old', $model->getAttribute('name'));
    }

    public function test_policy_is_the_authoritative_resolver_class_snapshot(): void
    {
        $policy = new SearchDependencyPolicy(true, 10, [CountingDependencyResolver::class]);

        $this->assertSame([CountingDependencyResolver::class], $policy->resolverClasses);
        $this->assertSame([CountingDependencyResolver::class], $policy->toArray()['resolvers']);
    }

    public function test_dependency_dto_invariants_reject_empty_update_attributes_and_invalid_maximum(): void
    {
        try {
            new SearchDependencyContext(
                $this->snapshot(),
                SearchDependencyOperation::Updated,
                SearchDependencyState::Before,
                'testing',
                [],
            );
            $this->fail('An update context accepted an empty changed-attribute list.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidSearchDependencyConfigurationException::class);
        new SearchDependencyTargetCollection([], 0);
    }

    public function test_dependency_configuration_direct_policy_requires_a_resolver_list(): void
    {
        foreach ([
            'associative' => ['named' => CountingDependencyResolver::class],
            'sparse' => [1 => CountingDependencyResolver::class],
            'out-of-sequence' => [0 => CountingDependencyResolver::class, 2 => ADependencyInfrastructureResolver::class],
        ] as $resolverClasses) {
            try {
                new SearchDependencyPolicy(true, 10, $resolverClasses);
                $this->fail('A non-list resolver configuration was accepted.');
            } catch (InvalidSearchDependencyConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dependency_configuration_direct_policy_rejects_objects_closures_and_duplicates(): void
    {
        foreach ([
            [new \stdClass],
            [static fn (): null => null],
            [CountingDependencyResolver::class, CountingDependencyResolver::class],
        ] as $resolverClasses) {
            try {
                new SearchDependencyPolicy(true, 10, $resolverClasses);
                $this->fail('An invalid resolver configuration was accepted.');
            } catch (InvalidSearchDependencyConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dependency_configuration_empty_section_uses_defaults(): void
    {
        config()->set('persian-search.dependencies', []);

        $policy = app(SearchDependencyPolicyFactory::class)->make();

        $this->assertTrue($policy->enabled);
        $this->assertSame(1000, $policy->maximumSourcesPerEvent);
        $this->assertSame([], $policy->resolverClasses);
    }

    public function test_dependency_configuration_partial_section_uses_omitted_defaults(): void
    {
        config()->set('persian-search.dependencies', ['enabled' => false]);

        $policy = app(SearchDependencyPolicyFactory::class)->make();

        $this->assertFalse($policy->enabled);
        $this->assertSame(1000, $policy->maximumSourcesPerEvent);
        $this->assertSame([], $policy->resolverClasses);
    }

    public function test_dependency_configuration_non_empty_positional_section_is_rejected(): void
    {
        config()->set('persian-search.dependencies', [true, 1000, []]);

        $this->expectException(InvalidSearchDependencyConfigurationException::class);
        app(SearchDependencyPolicyFactory::class)->make();
    }

    public function test_dependency_configuration_policy_serialization_preserves_list_order(): void
    {
        $classes = [ZDependencyInfrastructureResolver::class, ADependencyInfrastructureResolver::class];
        $policy = new SearchDependencyPolicy(true, 10, $classes);

        $this->assertSame($classes, $policy->toArray()['resolvers']);
    }

    public function test_dependency_configuration_diagnostics_redact_unsafe_resolver_class_text(): void
    {
        $unsafe = "Resolver\nSecret";
        $exception = InvalidSearchDependencyResolverException::forClass($unsafe);

        $this->assertStringNotContainsString($unsafe, $exception->getMessage());
        $this->assertStringContainsString(hash('sha256', $unsafe), $exception->getMessage());
        $this->assertStringContainsString('bytes='.strlen($unsafe), $exception->getMessage());
        $this->assertStringContainsString(
            CountingDependencyResolver::class,
            InvalidSearchDependencyResolverException::forClass(CountingDependencyResolver::class)->getMessage(),
        );
    }

    public function test_resolver_order_is_explicit_binary_for_numeric_and_case_sensitive_keys(): void
    {
        $registry = new SearchDependencyResolverRegistry(
            app(),
            new SearchDependencyPolicy(true, 10, [
                NumericTwoDependencyResolver::class,
                LowercaseDependencyResolver::class,
                NaturalTwoDependencyResolver::class,
                NumericTenDependencyResolver::class,
                UppercaseDependencyResolver::class,
                NaturalZeroTwoDependencyResolver::class,
            ]),
        );

        $this->assertSame(
            ['10', '2', 'A', 'a', 'resolver-02', 'resolver-2'],
            array_map(
                static fn (SearchDependencyResolverRegistration $registration): string => $registration->key,
                $registry->registrations(),
            ),
        );
        $this->assertSame(
            ['10', '2', 'A', 'a', 'resolver-02', 'resolver-2'],
            array_map(
                static fn (SearchDependencyResolverRegistration $registration): string => $registration->key,
                $registry->registrations(),
            ),
        );
    }

    public function test_binary_resolver_order_is_independent_of_configuration_order_and_model_first(): void
    {
        $classes = [
            SourceModelDependencyResolver::class,
            NumericTwoDependencyResolver::class,
            NumericTenDependencyResolver::class,
        ];
        $forward = new SearchDependencyResolverRegistry(
            app(),
            new SearchDependencyPolicy(true, 10, $classes),
        );
        $reverse = new SearchDependencyResolverRegistry(
            app(),
            new SearchDependencyPolicy(true, 10, array_reverse($classes)),
        );
        $identity = static fn (SearchDependencyResolverRegistration $registration): array => [
            $registration->dependencyModel,
            $registration->key,
            $registration->resolverClass,
        ];

        $this->assertSame(
            array_map($identity, $forward->registrations()),
            array_map($identity, $reverse->registrations()),
        );
        $this->assertSame(DependencyInfrastructureModel::class, $forward->registrations()[0]->dependencyModel);
        $this->assertSame(DependencySourceModel::class, $forward->registrations()[2]->dependencyModel);
    }

    public function test_binary_resolver_order_uses_class_as_the_final_tie_breaker(): void
    {
        $resolver = new ADependencyInfrastructureResolver;
        $left = new SearchDependencyResolverRegistration(
            $resolver,
            ADependencyInfrastructureResolver::class,
            'same',
            DependencyInfrastructureModel::class,
        );
        $right = new SearchDependencyResolverRegistration(
            $resolver,
            ZDependencyInfrastructureResolver::class,
            'same',
            DependencyInfrastructureModel::class,
        );
        $comparator = new \ReflectionMethod(SearchDependencyResolverRegistry::class, 'compareRegistrations');
        $result = $comparator->invoke(null, $left, $right);

        $this->assertIsInt($result);
        $this->assertLessThan(0, $result);
    }

    private function snapshot(): DependencyInfrastructureModel
    {
        $model = new DependencyInfrastructureModel;
        $model->setConnection('testing');
        $model->setRawAttributes(['id' => 7, 'group_id' => 1, 'name' => 'old'], true);
        $model->exists = true;

        return $model;
    }

    private function locator(string $id): SearchSourceLocator
    {
        return new SearchSourceLocator(
            new EloquentSearchSourceLocator(DependencySourceModel::class, 'testing', 'id', $id),
            'eloquent',
            new SearchSourceReference("source:{$id}", 'source', $id),
        );
    }
}

final class DependencyInfrastructureModel extends Model
{
    protected $guarded = [];
}

final class DependencySourceModel extends Model {}

final class ADependencyInfrastructureResolver implements SearchDependencyResolver
{
    public function key(): string
    {
        return 'a_dependency';
    }

    public function dependencyModel(): string
    {
        return DependencyInfrastructureModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        return [];
    }
}

final class ZDependencyInfrastructureResolver implements SearchDependencyResolver
{
    public function key(): string
    {
        return 'z_dependency';
    }

    public function dependencyModel(): string
    {
        return DependencyInfrastructureModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        return [];
    }
}

final class MutatingDependencyResolver implements SearchDependencyResolver
{
    public static ?Model $snapshot = null;

    public function key(): string
    {
        return 'a_mutating';
    }

    public function dependencyModel(): string
    {
        return DependencyInfrastructureModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        self::$snapshot = $context->dependency;
        $context->dependency->setAttribute('name', 'mutated');
        $context->dependency->setConnection('mutated');
        $context->dependency->setTable('mutated');
        $context->dependency->setRelation('mutated', new \stdClass);

        return [];
    }
}

final class InspectingDependencyResolver implements SearchDependencyResolver
{
    public static ?Model $snapshot = null;

    /** @var array<string, mixed> */
    public static array $attributes = [];

    public function key(): string
    {
        return 'b_inspecting';
    }

    public function dependencyModel(): string
    {
        return DependencyInfrastructureModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        self::$snapshot = $context->dependency;
        self::$attributes = $context->dependency->getAttributes();

        return [];
    }
}

final class UnstableDependencyModelResolver implements SearchDependencyResolver
{
    public static int $calls = 0;

    public function key(): string
    {
        return 'unstable_model';
    }

    public function dependencyModel(): string
    {
        self::$calls++;

        return self::$calls === 1
            ? DependencyInfrastructureModel::class
            : DependencySourceModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        return [];
    }
}

final class CountingDependencyResolver implements SearchDependencyResolver
{
    public static int $keyCalls = 0;

    public static int $modelCalls = 0;

    public function key(): string
    {
        self::$keyCalls++;

        return 'counting';
    }

    public function dependencyModel(): string
    {
        self::$modelCalls++;

        return DependencyInfrastructureModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        return [];
    }
}

final class UnstableDependencyKeyResolver implements SearchDependencyResolver
{
    public static int $calls = 0;

    public function key(): string
    {
        self::$calls++;

        return self::$calls === 1 ? 'first_key' : 'second_key';
    }

    public function dependencyModel(): string
    {
        return DependencyInfrastructureModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        return [];
    }
}

final class ConstructedDependencyResolver implements SearchDependencyResolver
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }

    public function key(): string
    {
        return 'constructed';
    }

    public function dependencyModel(): string
    {
        return DependencyInfrastructureModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        return [];
    }
}

abstract class KeyedDependencyResolver implements SearchDependencyResolver
{
    public function dependencyModel(): string
    {
        return DependencyInfrastructureModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        return [];
    }
}

final class NumericTenDependencyResolver extends KeyedDependencyResolver
{
    public function key(): string
    {
        return '10';
    }
}

final class NumericTwoDependencyResolver extends KeyedDependencyResolver
{
    public function key(): string
    {
        return '2';
    }
}

final class UppercaseDependencyResolver extends KeyedDependencyResolver
{
    public function key(): string
    {
        return 'A';
    }
}

final class LowercaseDependencyResolver extends KeyedDependencyResolver
{
    public function key(): string
    {
        return 'a';
    }
}

final class NaturalZeroTwoDependencyResolver extends KeyedDependencyResolver
{
    public function key(): string
    {
        return 'resolver-02';
    }
}

final class NaturalTwoDependencyResolver extends KeyedDependencyResolver
{
    public function key(): string
    {
        return 'resolver-2';
    }
}

final class SourceModelDependencyResolver implements SearchDependencyResolver
{
    public function key(): string
    {
        return 'source-model';
    }

    public function dependencyModel(): string
    {
        return DependencySourceModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        return [];
    }
}
