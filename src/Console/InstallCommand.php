<?php

namespace Zarbinco\PersianSearch\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'persian-search:install';

    protected $description = 'Publish the Persian search configuration and database migrations.';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'persian-search-config',
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'persian-search-migrations',
        ]);

        $this->components->info('Persian search assets published successfully.');
        $this->line('Next steps: php artisan migrate');
        $this->line('Optional spelling: php artisan persian-search:dictionary-build --force, then enable PERSIAN_SEARCH_SPELLING_ENABLED=true');

        return self::SUCCESS;
    }
}
