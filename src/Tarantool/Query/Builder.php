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
 * // Call stored procedure - call() picks procedure like on() picks connection
 * // Read: 1 map input
 * User::call('get_user')->where(['id' => $id])->first();
 * User::call('get_all_active_users')->where([])->first();
 * User::call('procedure')->where(['lat' => $lat, 'lng' => $lng])->first();
 * User::on('tarantool')->call('procedure')->where(['lat' => $lat, 'lng' => $lng])->first();
 * // Write: 2 maps input - search map + update map
 * User::call('assign_closest')->where(['lat' => $lat, 'lng' => $lng])->update(['status' => 'assigned']);
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
    protected ?string $procedure = null;
    protected static array $procedureSignatureCache = [];

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
     * If call() was used: runs procedure, returns first ReadOnlyRecord.
     * Otherwise: runs get() -> $space->select()
     */
    public function first($columns = ['*'])
    {
        // Enforce limit of 1 for single record lookups if not already set
        // Ignored when procedure is set - procedure does its own limiting
        if ($this->limit === null) {
            $this->limit(1);
        }

        $results = $this->get($columns);
        if ($results instanceof \Illuminate\Support\Collection) {
            return $results->first();
        }
        return $results[0] ?? null;
    }

    /**
     * Set query constraints strictly using an associative array.
     * Supports exact match: User::where(['status' => 'active'])
     * Supports operators: User::where(['age' => ['>=' => 18]])
     * Supports no-params for call(): User::call('proc')->where([])->first()
     * Supports array param for call(): User::call('find_closest')->where(['declined_ids' => [5,6]])->first()
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
     * Execute the query and return matching rows.
     * If call() was used: runs procedure with 1 map, returns Collection.
     * Otherwise: $space->select() with single index condition.
     */
    public function get($columns = ['*'])
    {
        // Procedure path: call -> where -> get() / first()
        if ($this->procedure !== null) {
            $this->validateProcedure($this->procedure, 1);

            if (!$this->whereCalled) {
                throw new \RuntimeException(
                    "Tarantool strict standard enforced: call() requires where() after it. " .
                    "Use Model::call('procedure')->where([...])->first() or ->where([])->first()"
                );
            }

            $params = [];
            foreach ($this->wheres as $column => $cond) {
                $params[$column] = is_array($cond) ? $cond['value'] : $cond;
            }

            $client = $this->connection->getTarantoolClient();
            // 1 map: where() map
            // $client->call('find_closest', ['latitude'=>...])
            // Lua: function(params)
            $response = $client->call($this->procedure, $params);

            if (!is_array($response) || $response === []) {
                return collect([]);
            }

            $returns = array_is_list($response) ? $response : [$response];
            $mapped = array_map(function ($row) {
                if (!is_array($row)) return $row;
                return new ReadOnlyRecord($row);
            }, $returns);

            return collect($mapped);
        }

        // Normal select path
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
     * Pick the stored procedure, like on() picks the connection.
     * Does NOT execute. Chain where() after it, then first() / get() / update().
     * Model::call('find_closest')->where([...])->first()
     * Model::on('tarantool')->call('find_closest')->where([...])->first()
     */
    public function call(string $procedure)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $procedure)) {
            throw new \InvalidArgumentException("Invalid procedure name: {$procedure}");
        }

        $this->procedure = $procedure;
        return $this;
    }

    /**
     * Validate that a procedure has the expected number of map params.
     * Read (first/get): 1 map -> function(params)
     * Write (update): 2 maps -> function(search, updates)
     * Errors out if user implemented their own procedure in the wrong format.
     */
    protected function validateProcedure(string $procedure, int $expectedParams): void
    {
        if (isset(self::$procedureSignatureCache[$procedure])) {
            $cached = self::$procedureSignatureCache[$procedure];
            if ($cached['unknown'] ?? false) {
                return;
            }
            $actual = $cached['nparams'];
            $isVararg = $cached['isvararg'] ?? false;
            if ($isVararg) {
                return;
            }
            if ($actual !== $expectedParams) {
                throw new \RuntimeException(
                    "Stored procedure '{$procedure}' is in the wrong format. " .
                    "Expected {$expectedParams} param(s) but it was defined with {$actual}. " .
                    ($expectedParams === 1
                        ? "For call->where->first() / get(), use: function(params)"
                        : "For call->where->update(), use: function(search, updates)")
                );
            }
            return;
        }

        $client = $this->connection->getTarantoolClient();

        $info = $client->evaluate("
            local name = '{$procedure}'
            -- 1. Global function defined in Lua file
            local f = _G[name]
            if f ~= nil then
                local ok, info = pcall(debug.getinfo, f, 'u')
                if ok and info ~= nil then
                    return {exists = true, nparams = info.nparams, isvararg = info.isvararg}
                end
            end
            -- 2. Stored function via box.schema.func.create({body=...})
            -- Body is in _func space, parse function(params) from it
            local t = box.space._func.index.name:get(name)
            if t == nil then return {exists = false} end
            local body = t[6] or t.body
            if body == nil or body == '' then return {exists = true, unknown = true} end
            local params_str = body:match('function%s*%(([^%)]*)%)')
            if params_str == nil then return {exists = true, unknown = true} end
            params_str = params_str:gsub('%s+', '')
            if params_str == '' then return {exists = true, nparams = 0, isvararg = false} end
            if params_str:find('%.%.%.') then return {exists = true, nparams = 0, isvararg = true} end
            local count = 1
            for _ in params_str:gmatch(',') do count = count + 1 end
            return {exists = true, nparams = count, isvararg = false}
        ");

        $data = [];
        if (is_array($info)) {
            $data = $info[0] ?? $info;
            if (!is_array($data)) {
                $data = [];
            }
        }

        if (($data['exists'] ?? false) === false) {
            throw new \RuntimeException("Stored procedure '{$procedure}' does not exist.");
        }

        if (!empty($data['unknown'])) {
            self::$procedureSignatureCache[$procedure] = ['unknown' => true];
            return;
        }

        $actual = (int) ($data['nparams'] ?? 0);
        $isVararg = !empty($data['isvararg']);
        self::$procedureSignatureCache[$procedure] = ['nparams' => $actual, 'isvararg' => $isVararg];

        if ($isVararg) {
            return;
        }

        if ($actual !== $expectedParams) {
            throw new \RuntimeException(
                "Stored procedure '{$procedure}' is in the wrong format. " .
                "Expected {$expectedParams} param(s) but it was defined with {$actual}. " .
                ($expectedParams === 1
                    ? "For call->where->first() / get(), use: function(params)"
                    : "For call->where->update(), use: function(search, updates)")
            );
        }
    }

    /**
     * Execute a strict exact-match update.
     * If call() was used: search+update procedure, 2 maps, returns ReadOnlyRecord|null.
     * Otherwise: per-tuple update, returns bool.
     */
    public function update(array $values): mixed
    {
        // Procedure path: call -> where -> update()
        if ($this->procedure !== null) {
            $this->validateProcedure($this->procedure, 2);

            if (!$this->whereCalled) {
                throw new \RuntimeException(
                    "Tarantool strict standard enforced: call() requires where() after it. " .
                    "Use Model::call('procedure')->where([...])->update([...])"
                );
            }

            if ($values === []) {
                throw new \InvalidArgumentException("update() requires update values, e.g. update(['status' => 'assigned'])");
            }

            $searchParams = [];
            foreach ($this->wheres as $column => $cond) {
                $searchParams[$column] = is_array($cond) ? $cond['value'] : $cond;
            }

            $client = $this->connection->getTarantoolClient();
            // 2 maps: where() map + update() map
            // $client->call('assign_closest', ['latitude'=>...], ['status'=>'assigned'])
            // Lua: function(search, updates)
            $response = $client->call($this->procedure, $searchParams, $values);

            if (!is_array($response) || $response === []) {
                return null;
            }

            $row = array_is_list($response) ? $response[0] : $response;
            if (!is_array($row)) return $row;
            return new ReadOnlyRecord($row);
        }

        // Normal per-tuple update path
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
     * If call() was used: procedure delete, 1 map.
     */
    public function delete()
    {
        // Procedure path: call -> where -> delete()
        if ($this->procedure !== null) {
            $this->validateProcedure($this->procedure, 1);

            if (!$this->whereCalled) {
                throw new \RuntimeException(
                    "Tarantool strict standard enforced: call() requires where() after it. " .
                    "Use Model::call('procedure')->where([...])->delete()"
                );
            }

            $params = [];
            foreach ($this->wheres as $column => $cond) {
                $params[$column] = is_array($cond) ? $cond['value'] : $cond;
            }

            $client = $this->connection->getTarantoolClient();
            $response = $client->call($this->procedure, $params);

            if (!is_array($response) || $response === []) {
                return null;
            }
            $row = array_is_list($response) ? $response[0] : $response;
            if (!is_array($row)) return $row;
            return new ReadOnlyRecord($row);
        }

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