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
 *
 * // Range select - ENABLED, returns many rows
 * User::where(['created_at' => ['>=' => $ts]])->limit(20)->offset(40)->get();
 * User::where(['score' => ['>' => 3]])->get(); // defaults to limit 1000
 *
 * // Multi-ID IN list - STILL BLOCKED, non-contiguous iteration
 * // User::where(['id' => [1,4,10]])->get(); // throws
 *
 * // limit / offset for procedure - appended to the SAME 1 map, Lua ignores if unused
 * User::call('list_orders')->where(['status' => 'active'])->limit(20)->offset(40)->get();
 *
 * // Ordering - STRICT: must be same column as where() for normal select
 * User::where(['created_at' => ['<=' => $now]])->orderBy('created_at', 'desc')->first();
 * User::where(['created_at' => ['>=' => $ts]])->orderByDesc('created_at')->limit(20)->get();
 * // Procedure: order_by / order_dir appended to same 1 map, Lua ignores if unused
 * User::call('list_orders')->where(['status' => 'active'])->orderBy('created_at', 'desc')->get();
 *
 * // Ordering - MISMATCHED 4: filter is right, order is wrong in Tarantool.
 * // Tarantool only has EQ, REQ, GT, GE, LT, LE. GT/GE are asc, LT/LE are desc.
 * // > + desc, >= + desc, < + asc, <= + asc have no single iterator for both filter and order.
 * // For those 4: fetch all with filter iterator, array_reverse() in PHP, return all.
 * // Slicing those 4 with limit()/offset() is unsupported and throws, because offset would skip from the wrong end.
 * // Use a procedure if you need those 4 with limit/offset.
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
    protected bool $hasExplicitLimit = false;
    protected bool $hasExplicitOffset = false;
    protected ?string $procedure = null;
    protected ?string $orderBy = null;
    protected string $orderDirection = 'asc';
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
        $this->hasExplicitLimit = true;
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
        $this->hasExplicitOffset = true;
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
     * Order by the index you're selecting on.
     * Normal select: $column MUST equal where() column, Tarantool can only
     * order by the index used for select.
     * Procedure: appended as order_by / order_dir to the same 1 map, Lua ignores if unused.
     */
    public function orderBy(string $column, string $direction = 'asc')
    {
        $direction = strtolower($direction);
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException(
                "Tarantool strict standard enforced: orderBy() direction must be 'asc' or 'desc', got '{$direction}'."
            );
        }

        if ($this->orderBy !== null) {
            throw new \BadMethodCallException(
                "Tarantool strict standard enforced: Chaining multiple orderBy() calls is not allowed. " .
                "A query can order by a single index only."
            );
        }

        $this->orderBy = $column;
        $this->orderDirection = $direction;
        return $this;
    }

    /**
     * Alias: orderBy($column, 'desc')
     */
    public function orderByDesc(string $column)
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Alias: orderBy($column, 'asc')
     */
    public function orderByAsc(string $column)
    {
        return $this->orderBy($column, 'asc');
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
        // For call() path this becomes params['limit'] = 1, Lua may ignore it.
        // For normal path this becomes Criteria limit 1.
        // Set directly, not via limit(), so it does not count as explicit slicing.
        if ($this->limit === null) {
            $this->limit = 1;
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
     * STRICT: No fallback to primary index. Ever.
     * If column != primaryKey and no index named $column exists on the space,
     * throw instead of silently using primary / full scan.
     */
    protected function assertIndexExists(string $column): void
    {
        if ($column === $this->primaryKey) {
            return;
        }

        $client = $this->connection->getTarantoolClient();

        $escapedTable = addslashes($this->table);
        $escapedColumn = addslashes($column);

        $info = $client->evaluate("return box.space['{$escapedTable}'].index['{$escapedColumn}'] ~= nil");
        $exists = is_array($info) ? ($info[0] ?? $info) : $info;

        if (! $exists) {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: No index '{$column}' on space '{$this->table}'. " .
                "Builder will NOT fallback to primary index '{$this->primaryKey}'. " .
                "Add index '{$column}' on '{$this->table}' or use a procedure: " .
                "Model::call('procedure')->where(['{$column}' => \$value])->first(). " .
                "Tried: where(['{$column}' => ...])->get()/first()"
            );
        }
    }

    /**
     * Get first part type of an index: 'unsigned', 'string', 'integer', 'number', etc.
     * Returns null if index doesn't exist (caller already threw) or type unknown.
     */
    protected function getIndexPartType(string $column): ?string
    {
        $client = $this->connection->getTarantoolClient();
        $escapedTable = addslashes($this->table);

        if ($column === $this->primaryKey) {
            // Primary index name is 'primary' in Tarantool, not the field name.
            $info = $client->evaluate("local idx = box.space['{$escapedTable}'].index['primary']; if idx == nil then return nil end; return idx.parts[1].type");
            $type = is_array($info) ? ($info[0] ?? null) : $info;
            return is_string($type) ? $type : null;
        }

        $escapedColumn = addslashes($column);
        $info = $client->evaluate("local idx = box.space['{$escapedTable}'].index['{$escapedColumn}']; if idx == nil then return nil end; return idx.parts[1].type");
        $type = is_array($info) ? ($info[0] ?? null) : $info;

        return is_string($type) ? $type : null;
    }

    /**
     * STRICT type check: don't let Tarantool give cryptic "expected unsigned".
     * If index is unsigned/integer and value is string, throw with clear message.
     * Builder will NOT coerce "123" -> 123 silently in get().
     */
    protected function assertKeyType(string $column, mixed $value): void
    {
        if (! is_string($value) && ! is_int($value)) {
            return;
        }

        $type = $this->getIndexPartType($column);
        if ($type === null) {
            return;
        }

        $isUnsigned = in_array($type, ['unsigned', 'integer'], true);
        $isString = in_array($type, ['string'], true);

        if ($isUnsigned && is_string($value)) {
            throw new \InvalidArgumentException(
                "Tarantool strict standard enforced: Field '{$column}' on '{$this->table}' expects {$type} int, got string '{$value}'. " .
                "Cast to (int) before where(): where(['{$column}' => (int) \$value]). " .
                "Builder will not coerce string -> int."
            );
        }

        if ($isString && is_int($value)) {
            throw new \InvalidArgumentException(
                "Tarantool strict standard enforced: Field '{$column}' on '{$this->table}' expects string, got int {$value}. " .
                "Cast to (string) before where(): where(['{$column}' => (string) \$value])."
            );
        }
    }

    /**
     * Execute the query and return matching rows.
     * If call() was used: runs procedure with 1 map, returns Collection.
     * Otherwise: $space->select() with single index condition.
     * - Exact (=): default limit 1
     * - Range (>, >=, <, <=): default limit 1000, use limit() to control
     * - Array value [1,4,10]: STILL BLOCKED, that's an IN list, not a range
     * - limit()/take() + offset()/skip() + orderBy() work for both normal and call() paths,
     *   except mismatched 4 with slicing: > + desc, >= + desc, < + asc, <= + asc + limit()/offset() throws.
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

            foreach ($this->wheres as $column => $cond) {
                $value = is_array($cond) ? $cond['value'] : $cond;
                $operator = is_array($cond) ? ($cond['operator'] ?? '=') : '=';
                // Range: send ['>=' => 300], so Lua check_value() sees the operator.
                // Exact: send scalar. Array param like declined_ids=[5,6]: send array as-is.
                $params[$column] = $operator !== '=' ? [$operator => $value] : $value;
            }

            // Enable limit()/take() + offset()/skip() + orderBy() for call()->where()->get()
            // Appended to the SAME 1 map, not as extra args.
            // Lua ignores limit/offset/order_by if not implemented, so safe to always send.
            // where() wins if you already put limit/offset/order_by in where().
            // Only inject if set - don't force a default, procedure does its own limiting.
            // first() already set limit = 1 before calling get(), so first() injects limit = 1.
            if (!isset($params['limit']) && $this->limit !== null) {
                $params['limit'] = $this->limit;
            }
            if (!isset($params['offset']) && $this->offset !== null) {
                $params['offset'] = $this->offset;
            }
            if ($this->orderBy !== null) {
                if (!isset($params['order_by'])) {
                    $params['order_by'] = $this->orderBy;
                }
                if (!isset($params['order_dir'])) {
                    $params['order_dir'] = $this->orderDirection;
                }
            }

            $client = $this->connection->getTarantoolClient();
            // 1 map: where() map + limit/offset/order_by if set
            // $client->call('find_closest', ['latitude'=>..., 'limit'=>1])
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

        // Normal select path - STRICT, no unbounded, no fallback
        if (empty($this->wheres)) {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: Unbounded select is strictly prohibited. " .
                "You must specify where() with an indexed column, or use a procedure: " .
                "Model::call('procedure')->where([])->first(). " .
                "Tried: ->get() with no where()"
            );
        }

        $space = $this->connection->getTarantoolClient()->getSpace($this->table);
        $rows = [];

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

        // STILL BLOCKED: array value like [1,4,10] is a non-contiguous IN list.
        // Tarantool select key [1,4,10] is a composite key, not IN.
        // Use range operator or procedure instead.
        if (is_array($value)) {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: Multi-ID / array-based lookups are strictly prohibited to prevent non-contiguous iteration. " .
                "Tried: where(['{$column}' => [1,4,10]]). Use range: where(['{$column}' => ['>=' => \$from]]) or a procedure."
            );
        }

        // STRICT: No fallback. If index doesn't exist, throw here, not in Tarantool.
        $this->assertIndexExists($column);
        $this->assertKeyType($column, $value);

        // STRICT ORDERING: orderBy must be same index as where() for normal select.
        // Tarantool can only order by the index used for select.
        if ($this->orderBy !== null && $this->orderBy !== $column) {
            throw new \RuntimeException(
                "Tarantool strict standard enforced: orderBy('{$this->orderBy}') must match where() index '{$column}' on '{$this->table}'. " .
                "Tarantool can only order by the index used for select. " .
                "Use a procedure if you need to order by a different field: " .
                "Model::call('procedure')->where([...])->orderBy('{$this->orderBy}', '{$this->orderDirection}')->get()."
            );
        }

        $isPrimary = ($column === $this->primaryKey);

        // Initialize criteria base - strictly on the requested index
        $criteria = $isPrimary 
            ? Criteria::key([$value]) 
            : Criteria::index($column)->andKey([$value]);

        // MISMATCHED 4: filter is right, order is wrong in Tarantool.
        // Tarantool only has EQ, REQ, GT, GE, LT, LE. GT/GE are asc, LT/LE are desc.
        // > + desc, >= + desc, < + asc, <= + asc have no single iterator for both filter and order.
        // For those 4: fetch all with filter iterator, array_reverse() in PHP, return all.
        // Slicing those 4 with explicit limit()/offset() is unsupported and throws,
        // because offset would skip from the wrong end. first() is allowed, it sets limit = 1 internally.
        $needsReverse = (
            ($operator === '>' && $this->orderDirection === 'desc') ||
            ($operator === '>=' && $this->orderDirection === 'desc') ||
            ($operator === '<' && $this->orderDirection === 'asc') ||
            ($operator === '<=' && $this->orderDirection === 'asc')
        );

        if ($needsReverse) {
            if ($this->hasExplicitLimit || $this->hasExplicitOffset) {
                throw new \RuntimeException(
                    "Tarantool strict standard enforced: {$operator} + {$this->orderDirection} with limit()/offset() is unsupported to slice. " .
                    "Tarantool iterator gives correct rows but wrong order, and offset would skip from the wrong end. " .
                    "Fetch all without limit/offset and slice in PHP, or use a procedure: " .
                    "Model::call('procedure')->where(['{$column}' => ['{$operator}' => \$value]])->orderBy('{$column}', '{$this->orderDirection}')->get()."
                );
            }

            $reverseCriteria = match ($operator) {
                '>'  => $criteria->andGtIterator(),
                '>=' => $criteria->andGeIterator(),
                '<'  => $criteria->andLtIterator(),
                '<=' => $criteria->andLeIterator(),
            };
            $reverseCriteria = $reverseCriteria->andLimit(1000);

            $result = $space->select($reverseCriteria);

            $reversed = [];
            foreach ($result as $tuple) {
                $reversed[] = $this->tupleToRow((array) $tuple);
            }

            return array_reverse($reversed);
        }

        // DIRECT 6: single select gives correct filter and correct order.
        // = + asc = EQ, = + desc = REQ
        // > + asc = GT, >= + asc = GE
        // < + desc = LT, <= + desc = LE
        if ($this->orderDirection === 'desc') {
            $criteria = match ($operator) {
                '<'  => $criteria->andLtIterator(),
                '<=' => $criteria->andLeIterator(),
                default => $criteria->andReqIterator(),
            };
        } else {
            $criteria = match ($operator) {
                '>'  => $criteria->andGtIterator(),
                '>=' => $criteria->andGeIterator(),
                default => $criteria,
            };
        }

        // Limit: exact = 1 by default (first() safety), range = 1000 by default
        // first() already set limit = 1 before calling get(), so first() + range still returns 1.
        $isRange = in_array($operator, ['>', '>=', '<', '<='], true);
        $effectiveLimit = $this->limit ?? ($isRange ? 1000 : 1);
        $criteria = $criteria->andLimit($effectiveLimit);

        // Apply offset if specified - works with both asc and desc because
        // offset is applied on the (possibly reversed) iterator server-side.
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

    # TODO: can remove schema introspection error?
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
                $value = is_array($cond) ? $cond['value'] : $cond;
                $operator = is_array($cond) ? ($cond['operator'] ?? '=') : '=';
                $searchParams[$column] = $operator !== '=' ? [$operator => $value] : $value;
            }

            $client = $this->connection->getTarantoolClient();
            $response = $client->call($this->procedure, $searchParams, $values);

            if (!is_array($response) || $response === []) {
                return null; // not found, allowed
            }

            // Must be just the map: [map] or map
            $row = array_is_list($response) ? ($response[0] ?? null) : $response;

            if ($row === null) {
                return null;
            }
            if (!is_array($row)) {
                throw new \RuntimeException(
                    "Stored procedure '{$this->procedure}' must return just the map, e.g. {id=1, name='James', status='inactive'}. " .
                    "Got non-array return."
                );
            }
            if (array_is_list($row)) {
                throw new \RuntimeException(
                    "Stored procedure '{$this->procedure}' must return just the map, not a tuple. " .
                    "Do: return {id = updated[1], name = updated[2], status = updated[3]}. " .
                    "Do not: return space:update(...)"
                );
            }
            if (count($response) > 1 && array_is_list($response)) {
                throw new \RuntimeException(
                    "Stored procedure '{$this->procedure}' must return just the map, not multiple rows. " .
                    "Update returns 1 record."
                );
            }

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
                $value = is_array($cond) ? $cond['value'] : $cond;
                $operator = is_array($cond) ? ($cond['operator'] ?? '=') : '=';
                // Range: send ['>=' => 300], so Lua check_value() sees the operator.
                // Exact: send scalar. Array param like declined_ids=[5,6]: send array as-is.
                $params[$column] = $operator !== '=' ? [$operator => $value] : $value;
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