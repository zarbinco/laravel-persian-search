<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;
use Throwable;
use Zarbinco\PersianSearch\Operations\SearchOperationExitCode;
use Zarbinco\PersianSearch\Operations\SearchOperationOutput;
use Zarbinco\PersianSearch\Operations\SearchStatusService;

final class StatusCommand extends Command
{
    protected $signature = 'persian-search:status {--json : Emit one JSON object}';

    protected $description = 'Show read-only Persian search operational status.';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        try {
            $status = app(SearchStatusService::class);
            $snapshot = $status->snapshot();
            if ($json) {
                $this->line(SearchOperationOutput::json($snapshot));
            } else {
                $this->components->info('Persian search status');
                $this->table(['Index', 'Value'], [
                    ['Connection', $snapshot->connection ?? 'default'],
                    ['Table', $snapshot->table],
                    ['Table exists', $snapshot->tableExists ? 'yes' : 'no'],
                    ['Documents', $snapshot->totalDocuments],
                    ['Active', $snapshot->activeDocuments],
                    ['Inactive', $snapshot->inactiveDocuments],
                    ['Maintenance lock status', $snapshot->maintenanceLockStatus->value],
                ]);
            }

            return SearchOperationExitCode::Success->value;
        } catch (Throwable) {
            $message = 'Persian search status infrastructure could not initialize safely.';
            $json ? $this->line(SearchOperationOutput::json(SearchOperationOutput::error($message, 'infrastructure_failure')))
                : $this->components->error($message);

            return SearchOperationExitCode::InfrastructureFailure->value;
        }
    }
}
