<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;
use Throwable;
use Zarbinco\PersianSearch\Exceptions\SearchMaintenanceLockUnavailableException;
use Zarbinco\PersianSearch\Operations\SearchOperationExitCode;
use Zarbinco\PersianSearch\Operations\SearchOperationFailureFormatter;
use Zarbinco\PersianSearch\Operations\SearchOperationOutput;
use Zarbinco\PersianSearch\Operations\SearchPruneOperation;
use Zarbinco\PersianSearch\Operations\SearchPruneRequest;

final class PruneCommand extends Command
{
    protected $signature = 'persian-search:prune
        {--enumerator=* : Select authoritative enumerator keys}
        {--provider=* : Select provider keys}
        {--limit= : Positive source limit}
        {--execute : Delete confirmed orphan document groups}
        {--json : Emit one JSON object}
        {--force : Skip execution confirmation}
        {--no-progress : Disable human progress output}';

    protected $description = 'Report or delete documents orphaned by authoritative enumeration.';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $rawLimit = $this->option('limit');
        $limit = $rawLimit === null ? null
            : (is_string($rawLimit) && preg_match('/^[1-9][0-9]*$/D', $rawLimit) === 1 ? (int) $rawLimit : false);
        if ($limit === false) {
            return $this->failure('Option --limit must be a positive integer.', $json);
        }
        $execute = (bool) $this->option('execute');
        if ($execute && ! (bool) $this->option('force')
            && (! $this->input->isInteractive() || ! $this->confirm('Delete all authoritatively identified orphan documents?'))) {
            return $this->failure('Prune execution confirmation is required.', $json, SearchOperationExitCode::ConfirmationRequired);
        }

        try {
            $operation = app(SearchPruneOperation::class);
            $report = $operation->run(new SearchPruneRequest(
                $this->strings($this->option('enumerator')),
                $this->strings($this->option('provider')),
                $limit,
                $execute,
            ));
            if ($json) {
                $this->line(SearchOperationOutput::json($report));
            } else {
                $this->components->info($execute ? 'Prune execution completed.' : 'Prune dry-run completed; nothing was deleted.');
                $this->table(['Metric', 'Count'], [
                    ['Current references', $report->currentSourceReferences],
                    ['Persisted references', $report->persistedSourceReferences],
                    ['Current documents', $report->currentDocuments],
                    ['Orphan references', $report->orphanedSourceReferences],
                    ['Orphan documents', $report->orphanedDocuments],
                    ['Deleted references', $report->deletedSourceReferences],
                    ['Deleted documents', $report->deletedDocuments],
                ]);
            }

            return SearchOperationExitCode::Success->value;
        } catch (SearchMaintenanceLockUnavailableException $exception) {
            return $this->failure($exception->getMessage(), $json, SearchOperationExitCode::LockUnavailable);
        } catch (Throwable $exception) {
            return $this->failure(app(SearchOperationFailureFormatter::class)->format($exception, 'prune'), $json);
        }
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
