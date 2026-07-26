<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;
use Throwable;
use Zarbinco\PersianSearch\Operations\SearchOperationExitCode;
use Zarbinco\PersianSearch\Operations\SearchOperationOutput;
use Zarbinco\PersianSearch\Spelling\SpellingDictionaryStatusService;

final class DictionaryStatusCommand extends Command
{
    protected $signature = 'persian-search:dictionary-status {--json : Emit one JSON object}';

    protected $description = 'Show read-only multilingual typo-correction dictionary status.';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        try {
            $status = app(SpellingDictionaryStatusService::class)->snapshot();
            if ($json) {
                $this->line(SearchOperationOutput::json($status));
            } else {
                $this->components->info('Persian search spelling dictionary status');
                $this->table(['Metric', 'Value'], [
                    ['Enabled', $status->enabled ? 'yes' : 'no'],
                    ['Terms table', $status->termsTable],
                    ['Deletes table', $status->deletesTable],
                    ['Tables ready', $status->termsTableExists && $status->deletesTableExists ? 'yes' : 'no'],
                    ['Terms', $status->terms],
                    ['Delete keys', $status->deletes],
                    ['Last built', $status->lastBuiltAt ?? 'never'],
                    ['Latest active document', $status->latestDocumentIndexedAt ?? 'none'],
                    ['Stale', $status->stale ? 'yes' : 'no'],
                    ['Correction profiles', $status->supportedProfiles === [] ? 'none' : implode(', ', $status->supportedProfiles)],
                    ['Phonetic ready', $status->phoneticReady ? 'yes' : 'no'],
                    ['Split ready', $status->splitReady ? 'yes' : 'no'],
                    ['Merge ready', $status->mergeReady ? 'yes' : 'no'],
                    ['Context n-gram table', $status->ngramsTable ?? 'not configured'],
                    ['Context n-gram table ready', $status->ngramsTableExists ? 'yes' : 'no'],
                    ['Context build metadata table', $status->buildsTable ?? 'not configured'],
                    ['Context build metadata ready', $status->buildsTableExists ? 'yes' : 'no'],
                    ['Context n-grams', $status->ngrams],
                    ['Contextual ready', $status->contextualReady ? 'yes' : 'no'],
                ]);
                if ($status->localeNgramCounts !== []) {
                    $this->table(
                        ['Locale', 'N-grams', 'Last built', 'Ready'],
                        array_map(
                            static fn (string $locale, int $count): array => [
                                $locale,
                                $count,
                                $status->ngramBuiltByLocale[$locale] ?? 'never',
                                ($status->contextualReadyByLocale[$locale] ?? false) ? 'yes' : 'no',
                            ],
                            array_keys($status->localeNgramCounts),
                            array_values($status->localeNgramCounts),
                        ),
                    );
                }
                foreach ($status->warnings as $warning) {
                    $this->components->warn($warning);
                }
            }

            return SearchOperationExitCode::Success->value;
        } catch (Throwable) {
            $message = 'Persian search spelling dictionary status could not initialize safely.';
            $json ? $this->line(SearchOperationOutput::json(SearchOperationOutput::error($message, 'infrastructure_failure')))
                : $this->components->error($message);

            return SearchOperationExitCode::InfrastructureFailure->value;
        }
    }
}
