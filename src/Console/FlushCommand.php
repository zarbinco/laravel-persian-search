<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;

final class FlushCommand extends Command
{
    protected $signature = 'persian-search:flush
        {sourceType? : Optional source type, including an Eloquent model class}
        {--partition= : Limit deletion to one partition}
        {--force : Flush without confirmation}';

    protected $description = 'Delete persisted Persian search documents.';

    public function handle(SearchIndexManager $index): int
    {
        $sourceType = $this->argument('sourceType');
        $partition = $this->option('partition');
        $partition = is_string($partition) && $partition !== '' ? $partition : null;

        if (is_string($sourceType) && $sourceType !== '') {
            $deleted = $index->flush($sourceType, $partition);
            $this->components->info("Deleted {$deleted} Persian search document(s).");

            return self::SUCCESS;
        }

        if (! (bool) $this->option('force') && ! $this->confirm('Delete all persisted Persian search documents?')) {
            $this->components->warn('Flush cancelled.');

            return self::FAILURE;
        }

        $deleted = $index->flush(partition: $partition);
        $this->components->info("Deleted {$deleted} Persian search document(s).");

        return self::SUCCESS;
    }
}
