<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;
use Throwable;
use Zarbinco\PersianSearch\Exceptions\SearchMaintenanceLockUnavailableException;
use Zarbinco\PersianSearch\Exceptions\SearchOperationExecutionException;
use Zarbinco\PersianSearch\Operations\SearchOperationExitCode;
use Zarbinco\PersianSearch\Operations\SearchOperationFailureFormatter;
use Zarbinco\PersianSearch\Operations\SearchOperationOutput;
use Zarbinco\PersianSearch\Operations\SearchReindexOperation;
use Zarbinco\PersianSearch\Operations\SearchReindexReport;
use Zarbinco\PersianSearch\Operations\SearchReindexRequest;

final class ReindexCommand extends Command
{
    protected $signature = 'persian-search:reindex
        {--enumerator=* : Select registered enumerator keys}
        {--provider=* : Select registered provider keys}
        {--sync : Execute synchronously}
        {--queue : Dispatch unique queued synchronizations}
        {--limit= : Positive source limit}
        {--dry-run : Enumerate and report without routing}
        {--json : Emit one JSON object}
        {--force : Skip write confirmation}
        {--no-progress : Disable human progress output}';

    protected $description = 'Safely reindex explicitly enumerated Persian search sources.';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if ((bool) $this->option('sync') && (bool) $this->option('queue')) {
            return $this->failure('Options --sync and --queue are mutually exclusive.', $json);
        }
        $limit = $this->limit($this->option('limit'));
        if ($limit === false) {
            return $this->failure('Option --limit must be a positive integer.', $json);
        }
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! (bool) $this->option('force')
            && (! $this->input->isInteractive() || ! $this->confirm('Proceed with Persian search reindexing?'))) {
            return $this->failure('Reindex confirmation is required.', $json, SearchOperationExitCode::ConfirmationRequired);
        }

        try {
            $operation = app(SearchReindexOperation::class);
            $report = $operation->run(new SearchReindexRequest(
                $this->strings($this->option('enumerator')),
                $this->strings($this->option('provider')),
                (bool) $this->option('sync') ? 'sync' : ((bool) $this->option('queue') ? 'queue' : null),
                $limit,
                $dryRun,
            ));
            $this->report($report, $json, $dryRun ? 'Reindex dry-run completed.' : 'Reindex completed.');

            return SearchOperationExitCode::Success->value;
        } catch (SearchMaintenanceLockUnavailableException $exception) {
            return $this->failure($exception->getMessage(), $json, SearchOperationExitCode::LockUnavailable);
        } catch (SearchOperationExecutionException $exception) {
            if (! $exception->partialReport instanceof SearchReindexReport) {
                return $this->failure('Search reindex execution failed safely.', $json);
            }
            $this->report(
                $exception->partialReport,
                $json,
                'Search reindex execution stopped safely.',
                $exception->getMessage(),
            );

            return SearchOperationExitCode::Failed->value;
        } catch (Throwable $exception) {
            return $this->failure(app(SearchOperationFailureFormatter::class)->format($exception, 'reindex'), $json);
        }
    }

    private function report(
        SearchReindexReport $report,
        bool $json,
        string $headline,
        ?string $failure = null,
    ): void {
        if ($json) {
            $this->line(SearchOperationOutput::json(
                $failure === null
                    ? $report
                    : SearchOperationOutput::executionFailure($report, $failure),
            ));

            return;
        }
        $failure === null
            ? $this->components->info($headline)
            : $this->components->error($failure);
        $this->table(['Metric', 'Count'], [
            ['Status', $report->status()],
            ['Enumerators', $report->enumerators],
            ['Enumerated', $report->enumerated],
            ['Unique sources', $report->uniqueSources],
            ['Duplicates', $report->duplicates],
            ['Synchronized', $report->synchronized],
            ['Queued', $report->queued],
            ['Suppressed', $report->suppressed],
            ['Failed', $report->failed],
            ['Unprocessed', $report->unprocessed],
        ]);
    }

    private function limit(mixed $value): int|null|false
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : false;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function failure(string $message, bool $json, SearchOperationExitCode $code = SearchOperationExitCode::Failed): int
    {
        $json ? $this->line(SearchOperationOutput::json(SearchOperationOutput::error($message)))
            : $this->components->error($message);

        return $code->value;
    }
}
