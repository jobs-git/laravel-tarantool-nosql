<?php

namespace Tarantool\Nosql\Console\Commands;

use Illuminate\Console\Command;

class Migrate extends Command
{
    protected $signature = 'tarantool:migrate {--fresh : Drop existing spaces before running migrations}';
    protected $description = 'Run custom Tarantool NoSQL migrations';

    public function handle()
    {
        $migrationPath = database_path('tarantool/migrations');

        if (!is_dir($migrationPath)) {
            $this->error("Tarantool migrations directory not found at: {$migrationPath}");
            return;
        }

        $files = glob($migrationPath . '/*.php');

        if (empty($files)) {
            $this->info("No Tarantool migration files found.");
            return;
        }

        sort($files);

        // Handle --fresh drop logic
        if ($this->option('fresh')) {
            $this->info('Dropping existing Tarantool spaces...');
            // Run down() in reverse order
            foreach (array_reverse($files) as $file) {
                $filename = basename($file);
                $this->line("Rolling down: <comment>{$filename}</comment>");
                $migration = require $file;
                if (method_exists($migration, 'down')) {
                    $migration->down();
                }
            }
            $this->info('Existing spaces dropped successfully.');
        }

        $this->info('Running Tarantool migrations...');

        foreach ($files as $file) {
            $filename = basename($file);
            $this->line("Processing: <comment>{$filename}</comment>");

            $migration = require $file;
            $migration->up();
            
            $this->info("Successfully executed: {$filename}");
        }

        $this->info('All Tarantool spaces created successfully!');
    }
}