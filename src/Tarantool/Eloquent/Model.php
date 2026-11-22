<?php

declare(strict_types=1);

namespace Tarantool\Nosql\Eloquent;

use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tarantool\Nosql\Query\Builder;
use Tarantool\Nosql\ReadOnlyRecord;

abstract class Model extends BaseModel
{
    /**
     * Normalize string 'null' representations from Tarantool tuples.
     * 
     * @param string $key
     * @return mixed|null
     */

    /**
     * Intercept static calls (e.g., User::find(1), User::create([...]))
     */
    public static function __callStatic($method, $parameters)
    {
        // FIXED: in-memory methods must NOT go to Builder.
        // Same safe list as __call, but for static context.
        // Without this, static::hydrate() is forwarded to Builder::hydrate() which does not exist.
        $safeInMemoryMethods = [
            'hydrate',
            'newCollection',
            'newFromBuilder',
            'qualifyColumn',
            'qualifyColumns',
            'getMorphClass',
            'getKey',
        ];

        if (in_array($method, $safeInMemoryMethods, true)) {
            $instance = new static();
            return $instance->$method(...$parameters);
        }

        static::validateTarantoolConfig();

        $instance = new static();

        // 1. Dynamically resolve connection name from model property, 
        //    runtime override, or fallback to 'tarantool'
        $connectionName = property_exists($instance, 'connection') && $instance->connection 
            ? $instance->connection 
            : (static::$connection ?? 'tarantool');

        // 2. Get the connection configuration safely
        $config = config("database.connections.{$connectionName}", []);
        $dbPrefix = $config['database'] ?? null;

        // 3. Resolve the table/space name idempotently
        $table = $instance->getTable();
        $spaceName = $table;

        if ($dbPrefix) {
            $prefixString = "{$dbPrefix}_";
            $unprefixedTable = str_starts_with($table, $prefixString) ? substr($table, strlen($prefixString)) : $table;
            $spaceName = "{$prefixString}{$unprefixedTable}";
        }
        
        $builder = new Builder(
            app("tarantool.connection.{$connectionName}"),
            $spaceName,
            $instance->getKeyName(),
            $instance->schema ?? []
        );

        return $builder->$method(...$parameters);
    }

    /**
     * Intercept instance method calls to block misuse.
     */
    public function __call($method, $parameters)
    {
        $safeInMemoryMethods = [
            'hydrate',
            'newCollection',
            'newFromBuilder',
            'qualifyColumn',
            'qualifyColumns',
            'getMorphClass',
            'getKey',
        ];

        if (in_array($method, $safeInMemoryMethods, true)) {
            return parent::__call($method, $parameters);
        }

        throw new \BadMethodCallException(
            "Method [" . static::class . "::{$method}] cannot be called dynamically or on an instance. Use static context [ClassName::{$method}()] instead."
        );
    }

    /**
     * Execute a raw expression or Lua script against the Tarantool connection.
     */
    public static function raw(string $expression)
    {
        return app('tarantool.connection.tarantool')->evaluate($expression);
    }

    /**
     * Call a stored procedure (Lua function) on the Tarantool server.
     */
    public static function call(string $procedure, ...$arguments)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $procedure)) {
            throw new \InvalidArgumentException("Invalid procedure name: {$procedure}");
        }

        $connectionName = static::$connection ?? 'tarantool';
        $connection = app("tarantool.connection.{$connectionName}");
        $client = $connection->getTarantoolClient();

        $args = (count($arguments) === 1 && is_array($arguments[0]))
            ? $arguments[0]
            : $arguments;

        $response = $client->call($procedure, $args);

        // $response from call() is [ , , ... ] or [ scalar ]
        // Normalize to same return as Builder: ReadOnlyRecord(s)
        if (!is_array($response)) {
            return $response;
        }

        // List of rows -> list of ReadOnlyRecord
        if (isset($response[0]) && is_array($response[0])) {
            return array_map(
                fn ($row) => new ReadOnlyRecord(is_array($row) ? $row : ['value' => $row]),
                $response
            );
        }

        // Single row -> single ReadOnlyRecord
        if (array_is_list($response) && count($response) > 0 && !is_array($response[0])) {
            // scalar list like [1, 2, 3] from a proc that returns numbers - keep as array
            return $response;
        }

        return new ReadOnlyRecord($response);
    }
    
    /**
     * Enforce Model Instance Immutability: Block $user->save()
     */
    public function save(array $options = [])
    {
        throw new \BadMethodCallException(
            "Model instances are immutable. Direct execution of \$user->save() is disabled. Use static context instead: " . static::class . "::update([...])"
        );
    }

    /**
     * Enforce Model Instance Immutability: Block $user->update()
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \BadMethodCallException(
            "Model instances are immutable. Direct execution of \$user->update() is disabled. Use static context instead: " . static::class . "::update([...])"
        );
    }

    /**
     * Enforce Model Instance Immutability: Block $user->delete()
     */
    public function delete()
    {
        throw new \BadMethodCallException(
            "Model instances are immutable. Direct execution of \$user->delete() is disabled. Use static context instead: " . static::class . "::delete(\$id)"
        );
    }

    /**
     * Enforce all zero-introspection and schema rules statically.
     */
    protected static function validateTarantoolConfig(): void
    {
        $instance = new static();

        if (empty($instance->schema)) {
            throw new \RuntimeException(
                "Model [" . static::class . "] must explicitly define a non-empty \$schema array to enforce zero-introspection mapping."
            );
        }

        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($instance), true);

        if ($usesSoftDeletes) {
            $deletedAtColumn = method_exists($instance, 'getDeletedAtColumn') 
                ? $instance->getDeletedAtColumn() 
                : 'deleted_at';

            if (!in_array($deletedAtColumn, $instance->schema, true)) {
                throw new \RuntimeException(
                    "Model [" . static::class . "] uses SoftDeletes, but the column '{$deletedAtColumn}' is missing from the explicit \$schema array."
                );
            }
        }

        $primaryKey = $instance->getKeyName();
        if (!in_array($primaryKey, $instance->schema, true)) {
            throw new \RuntimeException(
                "Model [" . static::class . "] primary key '{$primaryKey}' is missing from the explicit \$schema array."
            );
        }
    }

    public function getAttributeValue($key)
    {
        $value = parent::getAttributeValue($key);

        if (is_string($value) && strtolower($value) === 'null') {
            return null;
        }

        return $value;
    }
}