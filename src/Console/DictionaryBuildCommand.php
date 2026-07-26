<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;
use Throwable;
use Zarbinco\PersianSearch\Exceptions\SearchMaintenanceLockUnavailableException;
use Zarbinco\PersianSearch\Operations\SearchMaintenanceLockManager;
use Zarbinco\PersianSearch\Operations\SearchOperationExitCode;
use Zarbinco\PersianSearch\Operations\SearchOperationOutput;
use Zarbinco\PersianSearch\Spelling\SpellingDictionaryBuilder;

final class DictionaryBuildCommand extends Command
{
    protected $signature = 'persian-search:dictionary-build
        {--locale=* : Rebuild only these exact document locales}
        {--json : Emit one JSON object}
        {--force : Skip write confirmation}';

    protected $description = 'Build the multilingual typo-correction dictionary from active Persian search documents.';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if (! (bool) $this->option('force')
            && (! $this->input->isInteractive() || ! $this->confirm('Rebuild the Persian search spelling dictionary?'))) {
            return $this->failure('Dictionary build confirmation is required.', $json, SearchOperationExitCode::ConfirmationRequired);
        }

        $locales = $this->strings($this->option('locale'));
        try {
            $lock = app(SearchMaintenanceLockManager::class)->acquire();
            try {
                $result = app(SpellingDictionaryBuilder::class)->rebuild($locales);
            } finally {
                $lock->release();
            }

            if ($json) {
                $this->line(SearchOperationOutput::json($result));
            } else {
                $this->components->info('Persian search spelling dictionary built.');
                $this->table(['Metric', 'Count'], [
                    ['Documents scanned', $result->documents],
                    ['Dictionary terms', $result->terms],
                    ['Delete keys', $result->deletes],
                    ['Context bigrams', $result->ngrams],
                ]);
                if ($result->localeTermCounts !== []) {
                    $this->table(
                        ['Locale', 'Terms'],
                        array_map(
                            static fn (string $locale, int $count): array => [$locale, $count],
                            array_keys($result->localeTermCounts),
                            array_values($result->localeTermCounts),
                        ),
                    );
                }
            }

            return SearchOperationExitCode::Success->value;
        } catch (SearchMaintenanceLockUnavailableException $exception) {
            return $this->failure($exception->getMessage(), $json, SearchOperationExitCode::LockUnavailable);
        } catch (Throwable) {
            return $this->failure('Persian search spelling dictionary build failed safely.', $json);
        }
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }
            $values[] = trim($item);
        }

        return array_values(array_unique($values));
    }

    private function failure(string $message, bool $json, SearchOperationExitCode $code = SearchOperationExitCode::Failed): int
    {
        $json ? $this->line(SearchOperationOutput::json(SearchOperationOutput::error($message)))
            : $this->components->error($message);

        return $code->value;
    }
}
