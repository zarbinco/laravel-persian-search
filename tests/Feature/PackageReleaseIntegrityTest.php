<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;
use Zarbinco\PersianSearch\Operations\SearchPruneReport;
use Zarbinco\PersianSearch\Operations\SearchReindexReport;
use Zarbinco\PersianSearch\Tests\TestCase;

final class PackageReleaseIntegrityTest extends TestCase
{
    public function test_workflow_matrix_is_lockless_and_pairs_supported_frameworks(): void
    {
        $tests = $this->contents('.github/workflows/tests.yml');
        $quality = $this->contents('.github/workflows/quality.yml');
        $parsedTests = Yaml::parse($tests);
        $parsedQuality = Yaml::parse($quality);

        $this->assertIsArray($parsedTests);
        $this->assertIsArray($parsedQuality);
        $matrix = $parsedTests['jobs']['tests']['strategy']['matrix']['include'] ?? null;
        $this->assertIsArray($matrix);
        $this->assertCount(9, $matrix);
        $this->assertSame(['contents' => 'read'], $parsedTests['permissions'] ?? null);
        $this->assertSame(['contents' => 'read'], $parsedQuality['permissions'] ?? null);

        $lowest = ['12' => false, '13' => false];
        foreach ($matrix as $job) {
            $this->assertContains($job['dependencies'], ['highest', 'lowest']);
            $this->assertContains($job['laravel'], ['12', '13']);
            $this->assertSame($job['laravel'] === '12' ? '^12.61.1' : '^13.12.0', $job['illuminate']);
            $this->assertSame($job['laravel'] === '12' ? '^10.0' : '^11.0', $job['testbench']);
            $this->assertFalse($job['laravel'] === '13' && $job['php'] === '8.2');
            if ($job['dependencies'] === 'lowest') {
                $lowest[$job['laravel']] = true;
            }
        }
        $this->assertSame(['12' => true, '13' => true], $lowest);

        foreach (['bus', 'cache', 'console', 'contracts', 'database', 'queue', 'support'] as $component) {
            $this->assertStringContainsString("illuminate/{$component}:", $tests);
            $this->assertStringContainsString("\"illuminate/{$component}:^13.12.0\"", $quality);
        }
        $this->assertStringNotContainsString('prefer-stable', $tests.$quality);
        $this->assertStringNotContainsString('prefer-lowest', $tests.$quality);
        $this->assertStringNotContainsString('11.*', $tests);
        $this->assertStringContainsString("laravel: '12'", $tests);
        $this->assertStringContainsString("testbench: '^10.0'", $tests);
        $this->assertStringContainsString("laravel: '13'", $tests);
        $this->assertStringContainsString("testbench: '^11.0'", $tests);
        $this->assertStringContainsString('dependencies: lowest', $tests);
        $this->assertStringNotContainsString('composer-options: --with', $tests.$quality);
        $this->assertStringNotContainsString('dependency-versions: locked', $tests.$quality);
        $this->assertStringNotContainsString('audit.block-insecure', $tests.$quality);
        $this->assertStringNotContainsString('audit.ignore', $tests.$quality);
        $this->assertStringNotContainsString('COMPOSER_NO_AUDIT', $tests.$quality);
        $this->assertStringNotContainsString('--no-audit', $tests.$quality);
        $this->assertStringNotContainsString('--ignore', $tests.$quality);
        $this->assertDoesNotMatchRegularExpression('/\b(?:publish|release|tag)\b/i', $tests.$quality);
        $this->assertStringNotContainsString('secrets.', $tests.$quality);
        $this->assertStringNotContainsString('write', $tests.$quality);
    }

    public function test_composer_lock_is_absent_and_ignored(): void
    {
        $this->assertFileDoesNotExist($this->root('composer.lock'));
        $this->assertStringContainsString('composer.lock', $this->contents('.gitignore'));
        $this->assertFileDoesNotExist($this->root('DELETED_FILES.txt'));
    }

    public function test_repository_contains_no_internal_delivery_artifacts(): void
    {
        $entries = scandir($this->root(''));
        if (! is_array($entries)) {
            throw new \RuntimeException('Repository root could not be inspected.');
        }
        $artifacts = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry === 'DELETED_FILES.txt'
                || preg_match('/(?:phase|micro-patch).*notes/i', $entry) === 1
                || str_ends_with(strtolower($entry), '.zip'),
        ));

        $this->assertSame([], $artifacts);
    }

    public function test_laravel_support_contract_is_twelve_and_thirteen_only(): void
    {
        $composer = json_decode($this->contents('composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('^8.2', $composer['require']['php']);
        foreach ($composer['require'] as $package => $constraint) {
            if (str_starts_with($package, 'illuminate/')) {
                $this->assertSame('^12.61.1|^13.12.0', $constraint);
            }
        }
        $this->assertSame('^10.0|^11.0', $composer['require-dev']['orchestra/testbench']);
        $this->assertSame('^7.2|^8.0', $composer['require-dev']['symfony/yaml']);
    }

    public function test_operation_report_rejects_impossible_partial_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchReindexReport(
            mode: 'sync',
            dryRun: false,
            enumerators: 1,
            enumerated: 2,
            uniqueSources: 2,
            duplicates: 0,
            synchronized: 1,
            queued: 0,
            suppressed: 0,
            failed: 1,
            unprocessed: 1,
        );
    }

    public function test_operation_report_rejects_dry_run_execution_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchReindexReport(
            mode: 'sync',
            dryRun: true,
            enumerators: 1,
            enumerated: 1,
            uniqueSources: 1,
            duplicates: 0,
            synchronized: 1,
            queued: 0,
            suppressed: 0,
            failed: 0,
        );
    }

    public function test_prune_report_rejects_impossible_deletion_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchPruneReport(
            executed: true,
            providers: 1,
            authoritativeEnumerators: 1,
            currentSourceReferences: 0,
            persistedSourceReferences: 1,
            currentDocuments: 0,
            orphanedSourceReferences: 1,
            orphanedDocuments: 1,
            deletedSourceReferences: 2,
            deletedDocuments: 1,
        );
    }

    public function test_prune_report_rejects_dry_run_failures(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchPruneReport(
            executed: false,
            providers: 1,
            authoritativeEnumerators: 1,
            currentSourceReferences: 0,
            persistedSourceReferences: 1,
            currentDocuments: 0,
            orphanedSourceReferences: 1,
            orphanedDocuments: 1,
            deletedSourceReferences: 0,
            deletedDocuments: 0,
            failedSourceReferences: 1,
        );
    }

    public function test_operation_report_serializes_truthful_partial_status(): void
    {
        $report = new SearchPruneReport(
            executed: true,
            providers: 1,
            authoritativeEnumerators: 1,
            currentSourceReferences: 0,
            persistedSourceReferences: 3,
            currentDocuments: 0,
            orphanedSourceReferences: 3,
            orphanedDocuments: 3,
            deletedSourceReferences: 1,
            deletedDocuments: 1,
            failedSourceReferences: 1,
            unprocessedSourceReferences: 1,
        );

        $this->assertSame('partial_failure', $report->toArray()['status']);
        $this->assertSame($report->toArray(), $report->jsonSerialize());
        $this->assertSame([
            'status',
            'executed',
            'providers',
            'authoritative_enumerators',
            'current_source_references',
            'persisted_source_references',
            'current_documents',
            'orphaned_source_references',
            'orphaned_documents',
            'deleted_source_references',
            'deleted_documents',
            'failed_source_references',
            'unprocessed_source_references',
        ], array_keys($report->toArray()));
    }

    public function test_documentation_matches_implemented_operational_features(): void
    {
        $public = implode("\n", array_map(
            fn (string $path): string => $this->contents($path),
            [
                'README.md',
                'CONTRIBUTING.md',
                'SECURITY.md',
                'CHANGELOG.md',
                'docs/architecture.md',
                'docs/operations.md',
                'docs/release-checklist.md',
            ],
        ));
        $normalizedPublic = preg_replace('/\s+/', ' ', $public);
        if (! is_string($normalizedPublic)) {
            throw new \RuntimeException('Public documentation could not be normalized.');
        }

        $this->assertStringContainsString('Laravel 11 and earlier are not supported', $public);
        $this->assertStringNotContainsString(
            'Cursor pagination, dependency reindexing, cross-locale bridging, and typo-tolerant search are not provided.',
            $normalizedPublic,
        );
        $this->assertStringContainsString('dependency-aware reindexing', $public);
        $this->assertStringContainsString('cross-locale counterpart bridging', $public);
        $this->assertStringNotContainsString('model `--fresh`', $public);
        $this->assertStringNotContainsString('model reindex command', $public);
        $this->assertStringContainsString('Laravel 12 requires PHP 8.2 or later', $public);
        $this->assertStringContainsString('Laravel 13 requires PHP 8.3 or later', $public);
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root($path));
        if (! is_string($contents)) {
            throw new \RuntimeException('Release-integrity fixture could not be read.');
        }

        return $contents;
    }

    private function root(string $path): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
