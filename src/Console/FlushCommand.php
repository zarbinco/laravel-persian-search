<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;

final class FlushCommand extends Command
{
    protected $signature = 'persian-search:flush
        {model? : Optional fully-qualified searchable model class}
        {--force : Flush without confirmation}';

    protected $description = 'Delete persisted Persian search documents.';

    public function handle(SearchIndexManager $index): int
    {
        $modelClass = $this->argument('model');

        if (is_string($modelClass) && $modelClass !== '') {
            if (! $this->validSearchableModel($modelClass)) {
                return self::FAILURE;
            }

            $deleted = $index->flush($modelClass);
            $this->components->info("Deleted {$deleted} Persian search document(s).");

            return self::SUCCESS;
        }

        if (! (bool) $this->option('force') && ! $this->confirm('Delete all persisted Persian search documents?')) {
            $this->components->warn('Flush cancelled.');

            return self::FAILURE;
        }

        $deleted = $index->flush();
        $this->components->info("Deleted {$deleted} Persian search document(s).");

        return self::SUCCESS;
    }

    private function validSearchableModel(string $modelClass): bool
    {
        if (! class_exists($modelClass)) {
            $this->components->error("Model [{$modelClass}] does not exist.");

            return false;
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            $this->components->error("Class [{$modelClass}] must extend [".Model::class.'].');

            return false;
        }

        if (! is_subclass_of($modelClass, PersianSearchable::class)) {
            $this->components->error("Model [{$modelClass}] must implement [".PersianSearchable::class.'].');

            return false;
        }

        return true;
    }
}
