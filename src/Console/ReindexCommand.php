<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Providers\EloquentSearchDocumentProvider;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;

final class ReindexCommand extends Command
{
    protected $signature = 'persian-search:reindex
        {model : Fully-qualified searchable model class}
        {--chunk=100 : Number of records per chunk}
        {--fresh : Flush existing indexed documents for this model before reindexing}';

    protected $description = 'Rebuild persisted Persian search documents for a searchable model.';

    public function handle(
        SearchIndexManager $index,
        EloquentSearchDocumentProvider $eloquent,
        SearchDocumentProviderRegistry $providers,
    ): int {
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

        $fresh = (bool) $this->option('fresh');

        /** @var class-string<Model&PersianSearchable> $modelClass */
        $model = new $modelClass;
        $modelProvider = $providers->resolve($model);
        $customFresh = $fresh && ! $modelProvider instanceof EloquentSearchDocumentProvider;

        if ($fresh && ! $customFresh) {
            $deleted = $index->flush($modelClass);
            $this->components->info("Deleted {$deleted} existing Persian search document(s).");
        }

        if ($customFresh) {
            $this->components->warn(
                'Orphaned custom-provider sources for models no longer in the database are not removed; use an explicit source-type flush.',
            );
        }

        $query = $model->newQuery();
        $relations = $modelProvider instanceof EloquentSearchDocumentProvider
            ? $eloquent->relations($model)
            : [];

        if ($relations !== []) {
            $query->with($relations);
        }

        if ((bool) config('persian-search.index.include_soft_deleted', false)
            && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $withTrashed = [$query, 'withTrashed'];

            if (! is_callable($withTrashed)) {
                throw new LogicException('Soft-deleting model query does not support withTrashed().');
            }

            $withTrashed();
        }

        $sourceCount = 0;
        $documentCount = 0;
        $customDeletedCount = 0;
        $encounteredCustomFresh = $customFresh;

        $query->chunkById($chunk, function ($models) use (
            $index,
            $providers,
            $fresh,
            &$sourceCount,
            &$documentCount,
            &$customDeletedCount,
            &$encounteredCustomFresh,
        ): void {
            foreach ($models as $model) {
                $provider = $providers->resolve($model);

                if ($fresh && ! $provider instanceof EloquentSearchDocumentProvider) {
                    if (! $encounteredCustomFresh) {
                        $this->components->warn(
                            'Orphaned custom-provider sources for models no longer in the database are not removed; use an explicit source-type flush.',
                        );
                        $encounteredCustomFresh = true;
                    }

                    $set = $index->documentsFor($model);
                    $customDeletedCount += $index->deleteSourceReference($set->reference);
                    $documentCount += $index->indexDocumentSet($set)->count();
                } else {
                    $documentCount += $index->indexSource($model)->count();
                }

                $sourceCount++;
            }
        });

        if ($encounteredCustomFresh) {
            $this->components->info("Deleted {$customDeletedCount} current custom-provider Persian search document(s).");
        }

        $this->components->info("Indexed {$documentCount} Persian search document(s).");
        $this->components->info("Processed {$sourceCount} searchable source(s).");

        return self::SUCCESS;
    }
}
