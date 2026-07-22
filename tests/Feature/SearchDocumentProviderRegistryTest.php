<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Exceptions\AmbiguousSearchDocumentProviderException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDocumentProviderException;
use Zarbinco\PersianSearch\Exceptions\SearchDocumentProviderNotFoundException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Providers\EloquentSearchDocumentProvider;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchDocumentProviderRegistryTest extends TestCase
{
    public function test_configured_provider_resolves_through_container_with_dependencies_and_order(): void
    {
        app()->instance(VirtualProviderDependency::class, new VirtualProviderDependency('site'));
        config()->set('persian-search.providers', [VirtualPageProvider::class, NeverSupportingProvider::class]);
        $registry = app(SearchDocumentProviderRegistry::class);
        $source = new VirtualPageSource('about');

        $this->assertSame('virtual-pages', $registry->resolve($source)->key());
        $this->assertSame(['virtual-pages', 'never', 'eloquent'], array_map(static fn (SearchDocumentProvider $provider): string => $provider->key(), $registry->all()));
        $this->assertSame($registry->resolve($source), $registry->resolve($source));
        $this->assertInstanceOf(VirtualPageProvider::class, $registry->provider('virtual-pages'));
        $this->assertSame($registry->provider('virtual-pages'), $registry->provider(' virtual-pages '));
    }

    #[DataProvider('nonCanonicalProviderKeys')]
    public function test_provider_key_with_surrounding_whitespace_is_rejected(string $provider): void
    {
        config()->set('persian-search.providers', [$provider]);
        $this->expectException(InvalidSearchDocumentProviderException::class);
        $this->expectExceptionMessage('canonical');

        app(SearchDocumentProviderRegistry::class)->all();
    }

    /** @return array<string, array{class-string<SearchDocumentProvider>}> */
    public static function nonCanonicalProviderKeys(): array
    {
        return [
            'leading whitespace' => [LeadingWhitespaceKeyProvider::class],
            'trailing whitespace' => [TrailingWhitespaceKeyProvider::class],
        ];
    }

    public function test_provider_key_lookup_uses_a_focused_exception_and_rejects_empty_input(): void
    {
        $registry = app(SearchDocumentProviderRegistry::class);

        try {
            $registry->provider('missing');
            $this->fail('Expected unknown provider-key exception.');
        } catch (SearchDocumentProviderNotFoundException $exception) {
            $this->assertSame('No search document provider is registered with key [missing].', $exception->getMessage());
        }

        $this->expectException(InvalidSearchDocumentProviderException::class);
        $registry->provider('   ');
    }

    public function test_fallback_key_is_reserved_and_all_provider_keys_are_canonical(): void
    {
        config()->set('persian-search.providers', [ReservedFallbackKeyProvider::class]);
        $this->expectException(InvalidSearchDocumentProviderException::class);

        app(SearchDocumentProviderRegistry::class)->all();
    }

    public function test_provider_key_must_remain_stable_between_calls(): void
    {
        ChangingKeyProvider::$calls = 0;
        config()->set('persian-search.providers', [ChangingKeyProvider::class]);
        $this->expectException(InvalidSearchDocumentProviderException::class);
        $this->expectExceptionMessage('stable');

        app(SearchDocumentProviderRegistry::class)->all();
    }

    #[DataProvider('unsafeUnicodeProviderKeys')]
    public function test_unicode_provider_key_boundaries_and_invisible_characters_are_rejected(string $key): void
    {
        ConfigurableKeyProvider::$key = $key;
        config()->set('persian-search.providers', [ConfigurableKeyProvider::class]);
        $this->expectException(InvalidSearchDocumentProviderException::class);

        app(SearchDocumentProviderRegistry::class)->all();
    }

    /** @return array<string, array{string}> */
    public static function unsafeUnicodeProviderKeys(): array
    {
        return [
            'leading NBSP' => ["\u{00A0}products"],
            'trailing NBSP' => ["products\u{00A0}"],
            'leading EM SPACE' => ["\u{2003}products"],
            'trailing narrow NBSP' => ["products\u{202F}"],
            'leading ideographic space' => ["\u{3000}products"],
            'bidi override' => ["pro\u{202E}ducts"],
            'line separator' => ["pro\u{2028}ducts"],
            'paragraph separator' => ["pro\u{2029}ducts"],
            'bidi isolate' => ["pro\u{2066}ducts"],
            'LRM' => ["pro\u{200E}ducts"],
            'RLM' => ["pro\u{200F}ducts"],
            'zero width space' => ["pro\u{200B}ducts"],
            'ZWNJ' => ["pro\u{200C}ducts"],
            'ZWJ' => ["pro\u{200D}ducts"],
            'word joiner' => ["pro\u{2060}ducts"],
            'BOM' => ["pro\u{FEFF}ducts"],
            'C0 control' => ["pro\u{0001}ducts"],
            'C1 control' => ["pro\u{0085}ducts"],
            'Unicode whitespace only' => ["\u{00A0}\u{2003}\u{3000}"],
        ];
    }

    public function test_unicode_provider_key_lookup_trims_separators_and_preserves_visible_unicode(): void
    {
        ConfigurableKeyProvider::$key = 'محصولات';
        config()->set('persian-search.providers', [ConfigurableKeyProvider::class]);
        $registry = app(SearchDocumentProviderRegistry::class);

        $this->assertSame('محصولات', $registry->provider("\u{00A0}محصولات\u{3000}")->key());
        $this->assertSame($registry->provider('محصولات'), $registry->provider("\u{2003}محصولات\u{202F}"));
        $this->assertSame(['محصولات', 'eloquent'], array_map(
            static fn (SearchDocumentProvider $provider): string => $provider->key(),
            $registry->all(),
        ));
    }

    public function test_unicode_provider_key_lookup_rejects_format_controls_without_exposing_them(): void
    {
        $unsafe = "pro\u{202E}ducts\u{200B}";

        try {
            app(SearchDocumentProviderRegistry::class)->provider($unsafe);
            $this->fail('Expected unsafe provider-key lookup to fail.');
        } catch (InvalidSearchDocumentProviderException $exception) {
            $this->assertStringNotContainsString("\u{202E}", $exception->getMessage());
            $this->assertStringNotContainsString("\u{200B}", $exception->getMessage());
        }
    }

    public function test_custom_provider_wins_before_eloquent_fallback(): void
    {
        config()->set('persian-search.providers', [CustomEloquentProvider::class]);
        $model = new RegistrySearchableModel;
        $model->setRawAttributes(['id' => 12, 'title' => 'Product'], true);

        $this->assertSame('custom-models', app(SearchDocumentProviderRegistry::class)->resolve($model)->key());
    }

    public function test_fallback_handles_only_searchable_persisted_models(): void
    {
        $searchable = new RegistrySearchableModel;
        $searchable->setRawAttributes(['id' => 12, 'title' => 'Product'], true);
        $plain = new RegistryPlainModel;

        $this->assertInstanceOf(EloquentSearchDocumentProvider::class, app(SearchDocumentProviderRegistry::class)->resolve($searchable));
        $this->expectException(SearchDocumentProviderNotFoundException::class);
        app(SearchDocumentProviderRegistry::class)->resolve($plain);
    }

    public function test_unsupported_virtual_source_throws_provider_not_found(): void
    {
        $this->expectException(SearchDocumentProviderNotFoundException::class);
        app(SearchDocumentProviderRegistry::class)->resolve(new VirtualPageSource('about'));
    }

    public function test_multiple_custom_matches_are_ambiguous(): void
    {
        app()->instance(VirtualProviderDependency::class, new VirtualProviderDependency('site'));
        config()->set('persian-search.providers', [VirtualPageProvider::class, OtherVirtualPageProvider::class]);
        $this->expectException(AmbiguousSearchDocumentProviderException::class);
        $this->expectExceptionMessage('virtual-pages');

        app(SearchDocumentProviderRegistry::class)->resolve(new VirtualPageSource('about'));
    }

    /** @param list<string> $providers */
    #[DataProvider('invalidProviderConfigurations')]
    public function test_invalid_provider_configuration_is_rejected(array $providers): void
    {
        config()->set('persian-search.providers', $providers);
        $this->expectException(InvalidSearchDocumentProviderException::class);

        app(SearchDocumentProviderRegistry::class)->all();
    }

    /** @return array<string, array{list<string>}> */
    public static function invalidProviderConfigurations(): array
    {
        return [
            'missing' => [['Missing\\Provider\\Class']],
            'invalid contract' => [[stdClass::class]],
            'duplicate class' => [[NeverSupportingProvider::class, NeverSupportingProvider::class]],
            'duplicate key' => [[NeverSupportingProvider::class, DuplicateNeverProvider::class]],
            'empty key' => [[EmptyKeyProvider::class]],
        ];
    }

    public function test_constructor_resolution_failure_is_not_swallowed(): void
    {
        config()->set('persian-search.providers', [UnresolvableProvider::class]);
        $this->expectException(BindingResolutionException::class);

        app(SearchDocumentProviderRegistry::class)->all();
    }

    public function test_virtual_provider_creates_multiple_locales_and_null_source_id(): void
    {
        app()->instance(VirtualProviderDependency::class, new VirtualProviderDependency('site'));
        config()->set('persian-search.providers', [VirtualPageProvider::class]);
        $set = PersianSearch::documentsFor(new VirtualPageSource('about'));

        $this->assertCount(2, $set);
        $this->assertNull($set->reference->sourceId);
        $this->assertSame(['fa', 'en'], array_map(static fn (SearchDocument $document): string => $document->locale(), $set->all()));
    }
}

final readonly class VirtualPageSource
{
    public function __construct(public string $key) {}
}

final readonly class VirtualProviderDependency
{
    public function __construct(public string $prefix) {}
}

final readonly class VirtualPageProvider implements SearchDocumentProvider
{
    public function __construct(private VirtualProviderDependency $dependency) {}

    public function key(): string
    {
        return 'virtual-pages';
    }

    public function supports(mixed $source): bool
    {
        return $source instanceof VirtualPageSource;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        if (! $source instanceof VirtualPageSource) {
            throw new \LogicException;
        }

        return new SearchSourceReference($this->dependency->prefix.':'.$source->key, 'page', null);
    }

    public function documents(mixed $source): iterable
    {
        $reference = $this->reference($source);

        foreach (['fa' => 'درباره', 'en' => 'about'] as $locale => $title) {
            yield providerDocument($reference, 'public', $locale, $title);
        }
    }
}

final class OtherVirtualPageProvider implements SearchDocumentProvider
{
    public function key(): string
    {
        return 'other-pages';
    }

    public function supports(mixed $source): bool
    {
        return $source instanceof VirtualPageSource;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        return new SearchSourceReference('other', 'page', null);
    }

    public function documents(mixed $source): iterable
    {
        return [];
    }
}

class NeverSupportingProvider implements SearchDocumentProvider
{
    public function key(): string
    {
        return 'never';
    }

    public function supports(mixed $source): bool
    {
        return false;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        return new SearchSourceReference('never', 'never', null);
    }

    public function documents(mixed $source): iterable
    {
        return [];
    }
}

final class DuplicateNeverProvider extends NeverSupportingProvider
{
    public function key(): string
    {
        return 'never';
    }
}

final class EmptyKeyProvider extends NeverSupportingProvider
{
    public function key(): string
    {
        return ' ';
    }
}

final class LeadingWhitespaceKeyProvider extends NeverSupportingProvider
{
    public function key(): string
    {
        return ' products';
    }
}

final class TrailingWhitespaceKeyProvider extends NeverSupportingProvider
{
    public function key(): string
    {
        return 'products ';
    }
}

final class ReservedFallbackKeyProvider extends NeverSupportingProvider
{
    public function key(): string
    {
        return 'eloquent';
    }
}

final class ChangingKeyProvider extends NeverSupportingProvider
{
    public static int $calls = 0;

    public function key(): string
    {
        self::$calls++;

        return self::$calls === 1 ? 'stable-key' : 'changed-key';
    }
}

final class ConfigurableKeyProvider extends NeverSupportingProvider
{
    public static string $key = 'products';

    public function key(): string
    {
        return self::$key;
    }
}

final class UnresolvableProvider extends NeverSupportingProvider
{
    public function __construct(UnknownProviderDependency $dependency)
    {
        $dependency->resolved();
    }
}

interface UnknownProviderDependency
{
    public function resolved(): void;
}

final class CustomEloquentProvider extends NeverSupportingProvider
{
    public function key(): string
    {
        return 'custom-models';
    }

    public function supports(mixed $source): bool
    {
        return $source instanceof RegistrySearchableModel;
    }
}

final class RegistrySearchableModel extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $guarded = [];

    public function persianSearchableFields(): array
    {
        return ['title'];
    }
}

final class RegistryPlainModel extends Model {}

function providerDocument(SearchSourceReference $reference, string $partition, string $locale, string $title): SearchDocument
{
    return new SearchDocument(
        partition: $partition, sourceKey: $reference->sourceKey, sourceType: $reference->sourceType,
        sourceId: $reference->sourceId, locale: $locale, title: $title, excerpt: null,
        normalizedTitle: $title, normalizedExcerpt: null, normalizedKeywords: null, normalizedContent: $title,
    );
}
