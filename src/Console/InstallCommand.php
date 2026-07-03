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
        $this->line('Next step: php artisan migrate');

        return self::SUCCESS;
    }
}
