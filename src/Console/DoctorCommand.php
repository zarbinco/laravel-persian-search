<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;
use Throwable;
use Zarbinco\PersianSearch\Operations\SearchDoctorService;
use Zarbinco\PersianSearch\Operations\SearchOperationExitCode;
use Zarbinco\PersianSearch\Operations\SearchOperationOutput;

final class DoctorCommand extends Command
{
    protected $signature = 'persian-search:doctor
        {--deep : Run bounded semantic document sampling}
        {--strict : Treat warnings as a non-zero result}
        {--json : Emit one JSON object}';

    protected $description = 'Diagnose Persian search configuration and infrastructure.';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        try {
            $doctor = app(SearchDoctorService::class);
            $report = $doctor->run((bool) $this->option('deep'));
            if ($json) {
                $this->line(SearchOperationOutput::json($report));
            } else {
                $this->components->info('Persian search doctor');
                $this->table(
                    ['Check', 'Status', 'Message'],
                    array_map(static fn ($result): array => [
                        $result->key, $result->status->value, $result->message,
                    ], $report->results),
                );
            }

            return $report->exitCode((bool) $this->option('strict'))->value;
        } catch (Throwable) {
            $message = 'Persian search doctor infrastructure could not initialize safely.';
            $json ? $this->line(SearchOperationOutput::json(SearchOperationOutput::error($message, 'infrastructure_failure')))
                : $this->components->error($message);

            return SearchOperationExitCode::InfrastructureFailure->value;
        }
    }
}
