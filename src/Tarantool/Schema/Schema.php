<?php

namespace Tarantool\Nosql\Schema;

use Closure;

class Schema
{
    public static function create(string $table, Closure $callback): void
    {
        // Get the database name/namespace from  configuration
        $database = config('database.connections.tarantool.database', '');
        
        // If a database is configured, qualify it with a _ (e.g. "mydb_data")
        // Otherwise, fall back to just the table name
        $spaceName = $database ? "{$database}_{$table}" : $table;

        $blueprint = new Blueprint($spaceName);
        $callback($blueprint);
        $blueprint->executeCreation();
    }

    public static function drop(string $table): void
    {
        $database = config('database.connections.tarantool.database', '');
        $spaceName = $database ? "{$database}_{$table}" : $table;

        // Safely check using array bracket notation to prevent nil indexing errors
        $lua = sprintf("
            local space = box.space['%s']
            if space ~= nil then
                space:drop()
            end
        ", $spaceName);

        app('tarantool.connection.tarantool')->evaluate($lua);
    }

    public static function dropIfExists(string $table): void
    {
        static::drop($table);
    }
}