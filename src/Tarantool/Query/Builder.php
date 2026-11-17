<?php

namespace Tarantool\Nosql\Query;

use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;
use Tarantool\Nosql\Connection;


/**
 * Standardized Database Query Examples:
 * 
 * // Read a single record
 * $user = User::where(['id' => $id])->first();
 * 
 * // Update multiple records (Mass update style - fast, skips events)
 * User::where(['status' => 'pending'])->update(['status' => 'active']);
 */


class Builder
{
    protected Connection $connection;
    protected string $table;
    protected string $primaryKey;
    protected array $schema;
    protected array $wheres = [];
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
     */
    public function where($conditions)
    {
        if (!empty($this->wheres)) {
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

        $validOperators = ['>', '>=', '<', '<=', '='];

        foreach ($conditions as $column => $value) {
            $operator = '=';

            if (is_array($value)) {
                $opKey = array_key_first($value);
                
                // Check if it's a valid operator constraint (e.g. ['>=' => 18])
                if ($opKey && in_array($opKey, $validOperators, true)) {
                    $operator = $opKey;
                    $value = $value[$opKey];
                } else {
                    // Otherwise, it's a forbidden multi-ID lookup (e.g. [601, 603])
                    throw new \RuntimeException(
                        "Tarantool strict standard enforced: Multi-ID / array-based lookups are strictly prohibited to prevent non-contiguous iteration."
                    );
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
        // (Assuming your primary key field is 'id' or you can look it up dynamically)
        $_pk = null;
        foreach ($this->wheres as $where) {
            if (($where['column'] ?? null) === 'id' && ($where['operator'] ?? '=') === '=') {
                $_pk = $where['value'];
                break;
            }
        }

        if (empty($_pk)) {
            throw new \RuntimeException(
                "Tarantool delete() requires an exact-match primary key equality condition in the where() clause."
            );
        }

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

class ReadOnlyRecord extends \ArrayObject
{
    public function __construct(array $input = [])
    {
        parent::__construct($input, \ArrayObject::ARRAY_AS_PROPS);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        throw new \RuntimeException(
            "Tarantool strict standard enforced: Returned records are read-only. " .
            "Cannot mutate '{$key}' directly; use Model::where(['id' => \$id])->update([...])."
        );
    }

    public function offsetUnset(mixed $key): void
    {
        throw new \RuntimeException(
            "Tarantool strict standard enforced: Returned records are read-only. Cannot unset '{$key}'."
        );
    }
}