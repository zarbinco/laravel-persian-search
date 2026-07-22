<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Foundation\Application;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Zarbinco\PersianCore\Facades\Persian;
use Zarbinco\PersianSearch\Contracts\SearchNormalizer;
use Zarbinco\PersianSearch\Core\CoreSearchNormalizer;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\PersianSearchManager;
use Zarbinco\PersianSearch\Tests\TestCase;

final class PersianSearchFoundationTest extends TestCase
{
    public function test_config_is_loaded(): void
    {
        $this->assertSame('database', config('persian-search.driver'));
        $this->assertSame('default', config('persian-search.index.default_partition'));
        $this->assertSame('und', config('persian-search.index.undefined_locale'));
        $this->assertNull(config('persian-search.normalizer'));
        $this->assertNull(config('persian-search.index.queue'));
        $this->assertNull(config('persian-search.database.max_tokens'));
        $this->assertNull(config('persian-search.keyboard.layouts.fa_to_en'));
    }

    public function test_search_normalizer_contract_resolves_to_core_implementation(): void
    {
        $normalizer = $this->application()->make(SearchNormalizer::class);

        $this->assertInstanceOf(CoreSearchNormalizer::class, $normalizer);
    }

    public function test_manager_and_facade_delegate_normalization_to_persian_core(): void
    {
        $value = 'كیكِ شکلاتي';
        $expected = Persian::search($value)->normalize();

        $manager = $this->application()->make(PersianSearchManager::class);

        $this->assertSame($expected, $manager->normalize($value));
        $this->assertSame($expected, PersianSearch::normalize($value));
    }

    public function test_manager_and_facade_delegate_tokenization_to_persian_core(): void
    {
        $value = 'آب‌میوه سن‌ایچ';
        $expected = Persian::search($value)->tokens();

        $manager = $this->application()->make(PersianSearchManager::class);

        $this->assertSame($expected, $manager->tokens($value));
        $this->assertSame($expected, PersianSearch::tokens($value));
    }

    public function test_no_duplicated_normalizer_classes_exist_and_core_normalizer_delegates(): void
    {
        $forbiddenNames = [
            'PersianNormalizer',
            'ArabicNormalizer',
            'CharacterMap',
            'DigitNormalizer',
            'ZwnjNormalizer',
        ];

        foreach ($this->sourceFiles() as $file) {
            $contents = file_get_contents($file->getPathname());

            $this->assertIsString($contents);

            foreach ($forbiddenNames as $name) {
                $this->assertStringNotContainsString($name, $file->getBasename('.php'));
                $this->assertStringNotContainsString("class {$name}", $contents);
            }

            if ($file->getBasename('.php') !== 'KeyboardLayoutCorrector') {
                $this->assertStringNotContainsString("'q' => 'ض'", $contents);
                $this->assertStringNotContainsString("';' => 'ک'", $contents);
            }
        }

        $coreNormalizer = file_get_contents(__DIR__.'/../../src/Core/CoreSearchNormalizer.php');

        $this->assertIsString($coreNormalizer);
        $this->assertStringContainsString('Persian::search($value)->normalize()', $coreNormalizer);
        $this->assertStringContainsString('Persian::search($value)->tokens()', $coreNormalizer);
        $this->assertStringNotContainsString('array_values(Persian::search($value)->tokens())', $coreNormalizer);

        $documentBuilder = file_get_contents(__DIR__.'/../../src/Indexing/SearchDocumentBuilder.php');

        $this->assertIsString($documentBuilder);
        $this->assertStringContainsString('SearchNormalizer', $documentBuilder);
        $this->assertStringNotContainsString('Persian::search', $documentBuilder);
        $this->assertStringNotContainsString('preg_replace', $documentBuilder);

        $indexManager = file_get_contents(__DIR__.'/../../src/Indexing/SearchIndexManager.php');

        $this->assertIsString($indexManager);
        $this->assertStringContainsString('SearchDocumentBuilder', $indexManager);
        $this->assertStringNotContainsString('Persian::search', $indexManager);
        $this->assertStringNotContainsString('preg_replace', $indexManager);

        foreach ([
            __DIR__.'/../../src/Search/SearchQueryBuilder.php',
            __DIR__.'/../../src/Drivers/DatabaseSearchDriver.php',
            __DIR__.'/../../src/Ranking/BasicRanker.php',
        ] as $path) {
            $contents = file_get_contents($path);

            $this->assertIsString($contents);
            $this->assertStringNotContainsString('Persian::search', $contents);
            $this->assertStringNotContainsString('preg_replace', $contents);
        }

        $queryBuilder = file_get_contents(__DIR__.'/../../src/Search/SearchQueryBuilder.php');

        $this->assertIsString($queryBuilder);
        $this->assertStringContainsString('SearchNormalizer', $queryBuilder);

        foreach ([
            __DIR__.'/../../src/Query/DefaultQueryExpander.php',
            __DIR__.'/../../src/Query/SynonymExpander.php',
        ] as $path) {
            $contents = file_get_contents($path);

            $this->assertIsString($contents);
            $this->assertStringContainsString('SearchNormalizer', $contents);
            $this->assertStringNotContainsString('Persian::search', $contents);
            $this->assertStringNotContainsString('preg_replace', $contents);
        }
    }

    public function test_regression_no_fuzzy_typo_or_external_search_adapters_are_added(): void
    {
        $forbiddenNames = [
            'Fuzzy',
            'TypoCorrector',
            'Scout',
            'Meilisearch',
            'Elasticsearch',
        ];

        foreach ($this->sourceFiles() as $file) {
            $contents = file_get_contents($file->getPathname());

            $this->assertIsString($contents);

            foreach ($forbiddenNames as $name) {
                $this->assertStringNotContainsString($name, $file->getBasename('.php'));
                $this->assertStringNotContainsString("class {$name}", $contents);
            }
        }
    }

    public function test_public_documentation_does_not_expose_internal_phase_labels(): void
    {
        $forbidden = [
            'Phase 1',
            'Phase 2',
            'current phase',
            'this phase',
        ];

        foreach ($this->publicDocumentationFiles() as $path) {
            $contents = file_get_contents($path);

            $this->assertIsString($contents);

            foreach ($forbidden as $term) {
                $this->assertStringNotContainsString($term, $contents, "Unexpected internal label [{$term}] in [{$path}].");
            }
        }
    }

    /**
     * @return list<SplFileInfo>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../src'),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function application(): Application
    {
        $this->assertInstanceOf(Application::class, $this->app);

        return $this->app;
    }

    /**
     * @return list<string>
     */
    private function publicDocumentationFiles(): array
    {
        return [
            __DIR__.'/../../README.md',
            __DIR__.'/../../CHANGELOG.md',
            __DIR__.'/../../CONTRIBUTING.md',
            __DIR__.'/../../docs/release-checklist.md',
            __DIR__.'/../../docs/architecture.md',
        ];
    }
}
