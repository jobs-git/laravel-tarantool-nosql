<?php

namespace Tarantool\Nosql\Query;

use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;
use Tarantool\Nosql\Connection;
use Tarantool\Nosql\ReadOnlyRecord;

/**
 * Standardized Database Query Examples:
 * 
 * // Read a single record
 * $user = User::where(['id' => $id])->first();
 * 
 * // Update multiple records (Mass update style - fast, skips events)
 * User::where(['status' => 'pending'])->update(['status' => 'active']);
 * 
 * // Call stored procedure with param names, returns column names
 * User::where(['id' => $id])->call('get_user');
 * User::where([])->call('get_all_active_users');
 * User::where(['lat' => $lat, 'lng' => $lng])->call('find_closest')->first();
 */

class Builder
{
    protected Connection $connection;
    protected string $table;
    protected string $primaryKey;
    protected array $schema;
    protected array $wheres = [];
    protected bool $whereCalled = false;
    protected ?int $limit = null;
    protected ?int $offset = null;

    public function __construct(
        Connection $connection,
        string $table,
        string $primaryKey,
        array $schema
    ) {
        $this->connection = $connection;
        $this->table = $table;
        $this->primaryKey = $primaryKey;
        $this->schema = $schema;
    }

    /**
     * Set the maximum number of records to return.
     */
    public function limit(int $value)
    {
        $this->limit = $value;
        return $this;
    }

    /**
     * Alias for limit().
     */
    public function take(int $value)
    {
        return $this->limit($value);
    }

    /**
     * Set the number of records to skip.
     */
    public function offset(int $value)
    {
        $this->offset = $value;
        return $this;
    }

    /**
     * Alias for offset().
     */
    public function skip(int $value)
    {
        return $this->offset($value);
    }

    /**
     * Disabled to prevent shorthand shortcuts. Enforces explicit where-first syntax.
     */
    public function find($_pk, $columns = ['*'])
    {
        throw new \BadMethodCallException(
            "The find() method is disabled." .
            "Use explicit query syntax instead: " . static::class . "::where(['{$this->primaryKey}' => \$_pk])->first()"
        );
    }

    /**
     * Get the first matching record.
     * This is Builder::first() - runs get() -> $space->select()
     */
    public function first($columns = ['*'])
    {
        // Enforce limit of 1 for single record lookups if not already set
        if ($this->limit === null) {
            $this->limit(1);
        }

        $results = $this->get($columns);
        return $results[0] ?? null;
    }

    /**
     * Set query constraints strictly using an associative array.
     * Supports exact match: User::where(['status' => 'active'])
     * Supports operators: User::where(['age' => ['>=' => 18]])
     * Supports no-params for call(): User::where([])->call('procedure')
     * Supports array param for call(): User::where(['declined_ids' => [5,6]])->call('find_closest')
     */
    public function where($conditions)
    {
        if ($this->whereCalled) {
            throw new \BadMethodCallException(
                "Tarantool strict standard enforced: Chaining multiple where() calls is not allowed. " .
                "Pass all conditions inside a single associative array: User::where(['id' => 1, 'status' => 'active'])"
            );
        }

        if (!is_array($conditions)) {
            throw new \InvalidArgumentException(
                "Tarantool strict standard enforced: where() requires an associative array. " .
                "Use User::where(['id' => \$value]) instead of positional arguments."
            );
        }

        // Mark as called even for where([]) - so call() knows where() was used
        $this->whereCalled = true;

        $validOperators = ['>', '>=', '<', '<=', '='];

        foreach ($conditions as $column => $value) {
            $operator = '=';

            if (is_array($value)) {
                $opKey = array_key_first($value);
                
                // Check if it's a valid operator constraint (e.g. ['>=' => 18])
                if ($opKey !== null && is_string($opKey) && in_array($opKey, $validOperators, true)) {
                    $operator = $opKey;
                    $value = $value[$opKey];
                } else {
                    // Array param for call(), e.g. declined_ids = [5,6] or []
                    // DO NOT throw here - where() doesn't know if this is for call() or for bulk.
                    // get() / update() / delete() will throw if you try to use it for bulk.
                    // Keep $value as the whole array, operator stays '='
                }
            }

            // Store value and operator together in a single structure
            $this->wheres[$column] = [
                'value' => $value,
                'operator' => $operator
            ];
        }

        return $this;
    }

    /**
     * Execute the query and return matching rows using positional mapping.
     */
    public function get($columns = ['*'])
    {
        $space = $this->connection->getTarantoolClient()->getSpace($this->table);
        $rows = [];

        if (!empty($this->wheres)) {
            // Hard architectural defense: strictly forbid multi-criteria queries
            if (count($this->wheres) > 1) {
                throw new \RuntimeException(
                    "Tarantool strict standard enforced: Multiple where conditions or chained criteria are strictly prohibited. " .
                    "A query must target a single index/key condition."
                );
            }

            $column = array_key_first($this->wheres);
            $condition = $this->wheres[$column];
            
            // Extract value and operator safely
            $value = is_array($condition) ? $condition['value'] : $condition;
            $operator = is_array($condition) ? $condition['operator'] : '=';

            // Intentional error stays here: select cannot do non-contiguous array lookup
            // space:select can do bulk, but only consecutive via index, not random list [1,5,9]
            if (is_array($value)) {
                throw new \RuntimeException(
                    "Tarantool strict standard enforced: Multi-ID / array-based lookups are strictly prohibited to prevent non-contiguous iteration."
                );
            }

            $indexPos = array_search($column, $this->schema, true);
            $isPrimary = ($column === $this->primaryKey || ($indexPos !== false && $indexPos === 0));

            // Initialize criteria base
            $criteria = $isPrimary 
                ? Criteria::key([$value]) 
                : Criteria::index($column)->andKey([$value]);

            // Apply iterator modifier based on operator
            $criteria = match ($operator) {
                '>'  => $criteria->andGtIterator(),
                '>=' => $criteria->andGeIterator(),
                '<'  => $criteria->andLtIterator(),
                '<=' => $criteria->andLeIterator(),
                default => $criteria, // EQ is default
            };
        } else {
            $criteria = Criteria::allIterator();
        }

        // Apply limit safety cap (default to 1 if unspecified to prevent accidental flooding)
        $effectiveLimit = $this->limit ?? 1;
        $criteria = $criteria->andLimit($effectiveLimit);

        // Apply offset if specified
        if ($this->offset !== null) {
            $criteria = $criteria->andOffset($this->offset);
        }

        $result = $space->select($criteria);

        foreach ($result as $tuple) {
            $rows[] = $this->tupleToRow((array) $tuple);
        }

        return $rows;
    }

    /**
     * Call a stored procedure.
     * where() provides param names, procedure returns column names.
     * No schema enforcement - procedure is source of truth.
     * Returns Collection so ->first() after call() is Collection::first().
     */
    public function call(string $procedure)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $procedure)) {
            throw new \InvalidArgumentException("Invalid procedure name: {$procedure}");
        }

        if (!$this->whereCalled) {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: call() requires where() first. " .
                "Use where(['param0' => \$val0])->call('procedure') or where([])->call('procedure')"
            );
        }

        // where() already built the map, Builder just passes it through
        $params = [];
        foreach ($this->wheres as $column => $cond) {
            $params[$column] = is_array($cond) ? $cond['value'] : $cond;
        }

        $client = $this->connection->getTarantoolClient();

        // PASS THE MAP - not ...array_values($params)
        // where(['latitude'=>14.5]) -> $client->call('proc', ['latitude'=>14.5])
        // -> Lua: function(params) with params.latitude = 14.5
        // where([]) -> $client->call('proc', []) -> Lua gets empty table {}
        $response = $client->call($procedure, $params);

        if (!is_array($response)) {
            $response = [$response];
        }

        if ($response === []) {
            return collect([]);
        }

        $returns = array_is_list($response) ? $response : [$response];

        $mapped = array_map(function ($row) {
            if (!is_array($row)) return $row;
            return new ReadOnlyRecord($row);
        }, $returns);

        return collect($mapped);
    }

    /**
     * Execute a strict exact-match update.
     */
    public function update(array $values): bool
    {
        if (empty($this->wheres)) {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: Unbounded updates are strictly prohibited. " .
                "You must specify a where() condition targeting a single record."
            );
        }

        if (count($this->wheres) > 1) {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: Multiple where conditions or chained criteria are strictly prohibited."
            );
        }

        $column = array_key_first($this->wheres);
        $condition = $this->wheres[$column];
        $operator = is_array($condition) ? $condition['operator'] : '=';
        $value = is_array($condition) ? $condition['value'] : $condition;

        // Intentional error stays here: space:update is per-tuple, no bulk update
        if (is_array($value)) {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: Multi-ID / array-based lookups are strictly prohibited to prevent non-contiguous iteration."
            );
        }

        // STRICTNESS: Updates can only be performed using exact-match equality (=)
        if ($operator !== '=') {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: Updates can only be performed using exact-match equality (=). " .
                "Range operators (like '{$operator}') are restricted to select queries."
            );
        }

        $rawPk = $this->wheres[$this->primaryKey] ?? null;
        $_pk = is_array($rawPk) ? $rawPk['value'] : $rawPk;

        if (!$_pk) {
            throw new \RuntimeException(
                "Tarantool update() requires an explicit primary key '{$this->primaryKey}' defined in the where clause. " .
                "Example: User::where(['id' => \$id])->update([...])"
            );
        }

        // Ensure primary key is explicitly cast to integer to satisfy Tarantool's 'unsigned' index type
        $_pk = (int) $_pk;

        $space = $this->connection->getTarantoolClient()->getSpace($this->table);

        $operations = null;
        foreach ($values as $fieldName => $value) {
            $index = array_search($fieldName, $this->schema, true);
            if ($index === false) {
                throw new \InvalidArgumentException("Field '{$fieldName}' not found in explicit schema for table '{$this->table}'.");
            }

            if (!$operations) {
                $operations = Operations::set($index, $value);
            } else {
                $operations = $operations->andSet($index, $value);
            }
        }

        if ($operations) {
            $space->update([$_pk], $operations);
        }

        return true;
    }

    public function paginate(...$parameters)
    {
        throw new \BadMethodCallException(
            "Tarantool strict standard enforced: paginate() is not supported under  zero-introspection NoSQL architecture."
        );
    }
    /**
     * Insert a new record using positional schema mapping.
     */
    public function create(array $attributes): ReadOnlyRecord
    {
        $space = $this->connection->getTarantoolClient()->getSpace($this->table);

        $tuple = [];
        foreach ($this->schema as $index => $fieldName) {
            $tuple[$index] = $attributes[$fieldName] ?? null;
        }

        $response = $space->insert($tuple);
        $responseData = is_object($response) && method_exists($response, 'getData') 
            ? $response->getData() 
            : (is_array($response) ? $response : [$tuple]);

        $insertedTuple = (array) ($responseData[0] ?? $tuple);

        return $this->tupleToRow($insertedTuple);
    }

    /**
     * Delete a record directly using its primary key (runtime execution guard).
     */
    public function delete()
    {
        // 1. Prevent unbounded deletes (blanket table wipes)
        if (empty($this->wheres)) {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: Unbounded deletes are strictly prohibited. " .
                "You must specify a where() condition targeting a primary key."
            );
        }

        // 2. Extract and validate the primary key from the where clauses
        // FIXED: wheres is keyed by column: ['id' => ['value' => ..., 'operator' => ...]]
        // Use $this->primaryKey, not hardcoded 'id'
        $rawPk = $this->wheres[$this->primaryKey] ?? null;
        $operator = is_array($rawPk) ? ($rawPk['operator'] ?? '=') : '=';
        $_pk = is_array($rawPk) ? ($rawPk['value'] ?? null) : $rawPk;

        // Intentional error stays here: delete is per-tuple, no bulk delete
        if (is_array($_pk)) {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: Multi-ID / array-based lookups are strictly prohibited to prevent non-contiguous iteration."
            );
        }

        if ($operator !== '=') {
            throw new \RuntimeException(
                "Tarantool delete() requires an exact-match primary key equality condition in the where() clause."
            );
        }

        if ($_pk === null || $_pk === '') {
            throw new \RuntimeException(
                "Tarantool delete() requires an exact-match primary key equality condition in the where() clause."
            );
        }

        // Ensure primary key is explicitly cast to integer to satisfy Tarantool's 'unsigned' index type
        // Same as update()
        $_pk = (int) $_pk;

        $space = $this->connection->getTarantoolClient()->getSpace($this->table);

        return $space->delete([$_pk]);
    }

    /**
     * Convert a raw numerical Tarantool tuple back into an associative array.
     */
    protected function tupleToRow(array $tuple): ReadOnlyRecord
    {
        $row = [];
        foreach ($tuple as $index => $value) {
            if (!isset($this->schema[$index])) {
                throw new \OutOfBoundsException(
                    "Tarantool tuple index [{$index}] in table '{$this->table}' has no matching schema field name."
                );
            }
            $row[$this->schema[$index]] = $value;
        }

        return new ReadOnlyRecord($row);
    }
}