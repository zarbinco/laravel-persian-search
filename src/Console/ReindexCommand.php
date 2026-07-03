<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;

final class ReindexCommand extends Command
{
    protected $signature = 'persian-search:reindex
        {model : Fully-qualified searchable model class}
        {--chunk=100 : Number of records per chunk}
        {--fresh : Flush existing indexed documents for this model before reindexing}';

    protected $description = 'Rebuild persisted Persian search documents for a searchable model.';

    public function handle(SearchIndexManager $index): int
    {
        $modelClass = $this->argument('model');
        $chunk = max(1, (int) $this->option('chunk'));

        if (! is_string($modelClass) || $modelClass === '') {
            $this->components->error('A fully-qualified searchable model class is required.');

            return self::FAILURE;
        }

        if (! class_exists($modelClass)) {
            $this->components->error("Model [{$modelClass}] does not exist.");

            return self::FAILURE;
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            $this->components->error("Class [{$modelClass}] must extend [".Model::class.'].');

            return self::FAILURE;
        }

        if (! is_subclass_of($modelClass, PersianSearchable::class)) {
            $this->components->error("Model [{$modelClass}] must implement [".PersianSearchable::class.'].');

            return self::FAILURE;
        }

        if ((bool) $this->option('fresh')) {
            $deleted = $index->flush($modelClass);
            $this->components->info("Deleted {$deleted} existing Persian search document(s).");
        }

        /** @var class-string<Model&PersianSearchable> $modelClass */
        $model = new $modelClass;
        $count = 0;

        $model->newQuery()->chunkById($chunk, function ($models) use ($index, &$count): void {
            foreach ($models as $model) {
                $index->index($model);
                $count++;
            }
        });

        $this->components->info("Indexed {$count} Persian search document(s).");

        return self::SUCCESS;
    }
}
