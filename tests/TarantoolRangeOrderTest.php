<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Tarantool\Client\Exception\RequestFailed;
use Tarantool\Nosql\Eloquent\Model;
use Tarantool\Nosql\Schema\Blueprint;
use Tarantool\Nosql\Schema\Schema;

class TestRangeOrder extends Model
{
    protected $table = 'test_range_orders';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    public array $schema = ['id', 'created_at', 'score', 'status', 'note'];
}

beforeEach(function () {
    Capsule::connection()->evaluate("
        local space = box.space['app_test_range_orders']
        if space ~= nil then space:drop() end
        list_orders = nil
        search_orders = nil
        find_closest = nil
    ");

    Schema::create('test_range_orders', function (Blueprint $table) {
        $table->id('id');
        $table->unsigned('created_at')->index();
        $table->unsigned('score')->index();
        $table->string('status')->index();
        $table->string('note')->nullable(); // no index on purpose
    });

    // Procedures: Builder passes ONE map: where + limit/offset/order_by/order_dir
    // Range is sent as operator map: created_at = {['>=' => 300]}
    // Returns maps + unpack, not a single table of tuples.
    Capsule::connection()->evaluate(<<<'LUA'
        local SPACE = 'app_test_range_orders'
        local _unpack = table.unpack or unpack

        local function to_map(tuple)
            return {id = tuple[1], created_at = tuple[2], score = tuple[3], status = tuple[4], note = tuple[5]}
        end

        local function check_value(tuple_val, v)
            if type(v) == 'table' then
                for op, val in pairs(v) do
                    if op == '>=' and not (tuple_val >= val) then return false end
                    if op == '>' and not (tuple_val > val) then return false end
                    if op == '<=' and not (tuple_val <= val) then return false end
                    if op == '<' and not (tuple_val < val) then return false end
                    if op == '=' and not (tuple_val == val) then return false end
                end
                return true
            end
            return tuple_val == v
        end

        local function matches(tuple, params)
            if params == nil then return true end
            if params.id ~= nil and not check_value(tuple[1], params.id) then return false end
            if params.created_at ~= nil and not check_value(tuple[2], params.created_at) then return false end
            if params.score ~= nil and not check_value(tuple[3], params.score) then return false end
            if params.status ~= nil and not check_value(tuple[4], params.status) then return false end
            return true
        end

        local function apply(params)
            local space = box.space[SPACE]
            local collected = {}
            for _, tuple in space:pairs() do
                if matches(tuple, params) then table.insert(collected, to_map(tuple)) end
            end

            local order_by = params ~= nil and params.order_by or nil
            local order_dir = params ~= nil and params.order_dir or 'asc'
            if order_by ~= nil then
                table.sort(collected, function(a, b)
                    if order_dir == 'desc' then return a[order_by] > b[order_by] else return a[order_by] < b[order_by] end
                end)
            end

            local limit = params ~= nil and params.limit or nil
            local offset = params ~= nil and params.offset or 0
            if limit ~= nil or (offset ~= nil and offset > 0) then
                local sliced = {}
                local start = (offset or 0) + 1
                local finish = start + (limit or #collected) - 1
                for i = start, math.min(finish, #collected) do
                    table.insert(sliced, collected[i])
                end
                return sliced
            end
            return collected
        end

        function list_orders(params) return _unpack(apply(params)) end
        function search_orders(params) return _unpack(apply(params)) end
        function find_closest(params) return _unpack(apply(params)) end
    LUA);

    // Seed: 5 rows, same for every test
    TestRangeOrder::create(['id' => 1, 'created_at' => 100, 'score' => 10, 'status' => 'active', 'note' => 'one']);
    TestRangeOrder::create(['id' => 2, 'created_at' => 200, 'score' => 20, 'status' => 'active', 'note' => 'two']);
    TestRangeOrder::create(['id' => 3, 'created_at' => 300, 'score' => 30, 'status' => 'pending', 'note' => 'three']);
    TestRangeOrder::create(['id' => 4, 'created_at' => 400, 'score' => 40, 'status' => 'active', 'note' => 'four']);
    TestRangeOrder::create(['id' => 5, 'created_at' => 500, 'score' => 50, 'status' => 'pending', 'note' => 'five']);
});

afterEach(function () {
    Capsule::connection()->evaluate("
        local space = box.space['app_test_range_orders']
        if space ~= nil then space:drop() end
        list_orders = nil
        search_orders = nil
        find_closest = nil
    ");
});

function ids($rows) {
    // call()->get() returns Collection, non-call get() returns array. Handle both.
    return collect($rows)->map(fn ($r) => $r['id'])->values()->all();
}

// ===========================================================================
// 1. RANGE - non-call, Eloquent-like
// ===========================================================================

test('range select >= returns rows in index order', function () {
    $rows = TestRangeOrder::where(['created_at' => ['>=' => 200]])->get();

    expect(ids($rows))->toBe([2, 3, 4, 5]);
});

test('range select > with limit and offset', function () {
    $rows = TestRangeOrder::where(['score' => ['>' => 20]])->limit(2)->offset(1)->get();

    // score > 20 = [3,4,5], offset 1 limit 2 = [4,5]
    // > + asc is direct, slicing allowed
    expect(ids($rows))->toBe([4, 5]);
});

test('range select <= with take and skip aliases uses direct combo', function () {
    // <= + asc is mismatched + slicing, would throw. Use <= + desc instead, which is direct.
    $rows = TestRangeOrder::where(['created_at' => ['<=' => 300]])->orderByDesc('created_at')->take(1)->skip(2)->get();

    // created_at <= 300 desc = [3,2,1], skip 2 take 1 = [1]
    expect(ids($rows))->toBe([1]);
});

test('exact select defaults to limit 1', function () {
    $row = TestRangeOrder::where(['id' => 1])->first();

    expect($row)->not->toBeNull()
        ->and($row['id'])->toBe(1);
});

test('range select blocks multi-id array', function () {
    expect(fn () => TestRangeOrder::where(['id' => [1, 4, 10]])->get())
        ->toThrow(RuntimeException::class, 'Multi-ID');
});

test('range select throws when querying column without index', function () {
    expect(fn () => TestRangeOrder::where(['note' => 'one'])->first())
        ->toThrow(RuntimeException::class, "No index 'note'");
});

test('range select throws on string for unsigned index', function () {
    expect(fn () => TestRangeOrder::where(['created_at' => '123'])->get())
        ->toThrow(InvalidArgumentException::class, 'expects unsigned');
});

test('range select throws unbounded select', function () {
    expect(fn () => TestRangeOrder::where([])->get())
        ->toThrow(RuntimeException::class, 'Unbounded select');
});

// ===========================================================================
// 2. ORDERBY - non-call
// ===========================================================================

test('orderby desc on same index works without slicing', function () {
    // >= + desc is mismatched. Without limit/offset it fetches all, reverses in PHP.
    $rows = TestRangeOrder::where(['created_at' => ['>=' => 100]])
        ->orderBy('created_at', 'desc')->get();

    expect(ids($rows))->toBe([5, 4, 3, 2, 1]);
});

test('orderbyDesc alias + first returns 1 row', function () {
    $row = TestRangeOrder::where(['created_at' => ['<=' => 500]])
        ->orderByDesc('created_at')->first();

    expect($row)->not->toBeNull()
        ->and($row['id'])->toBe(5);
});

test('orderby throws when column != where index', function () {
    expect(fn () => TestRangeOrder::where(['created_at' => ['>=' => 100]])
        ->orderBy('score', 'desc')->get())
        ->toThrow(RuntimeException::class, 'must match where');
});

test('orderby throws on invalid direction', function () {
    expect(fn () => TestRangeOrder::where(['created_at' => ['>=' => 100]])
        ->orderBy('created_at', 'sideways')->get())
        ->toThrow(InvalidArgumentException::class, 'asc');
});

// ===========================================================================
// 2b. MISMATCHED 4 - without slicing, PHP reverse
// ===========================================================================

test('mismatched > + desc without slicing returns reversed', function () {
    $rows = TestRangeOrder::where(['created_at' => ['>' => 100]])
        ->orderBy('created_at', 'desc')->get();

    expect(ids($rows))->toBe([5, 4, 3, 2]);
});

test('mismatched >= + desc without slicing returns reversed', function () {
    $rows = TestRangeOrder::where(['created_at' => ['>=' => 100]])
        ->orderByDesc('created_at')->get();

    expect(ids($rows))->toBe([5, 4, 3, 2, 1]);
});

test('mismatched < + asc without slicing returns reversed', function () {
    $rows = TestRangeOrder::where(['created_at' => ['<' => 400]])
        ->orderByAsc('created_at')->get();

    expect(ids($rows))->toBe([1, 2, 3]);
});

test('mismatched <= + asc without slicing returns reversed', function () {
    $rows = TestRangeOrder::where(['created_at' => ['<=' => 300]])
        ->orderByAsc('created_at')->get();

    expect(ids($rows))->toBe([1, 2, 3]);
});

test('mismatched + first is allowed, not explicit slicing', function () {
    $row = TestRangeOrder::where(['created_at' => ['>' => 100]])
        ->orderByDesc('created_at')->first();

    expect($row)->not->toBeNull()
        ->and($row['id'])->toBe(5);
});

test('mismatched < + asc + first returns smallest', function () {
    $row = TestRangeOrder::where(['created_at' => ['<' => 400]])
        ->orderByAsc('created_at')->first();

    expect($row)->not->toBeNull()
        ->and($row['id'])->toBe(1);
});

// ===========================================================================
// 2c. MISMATCHED 4 - with slicing, throws unsupported to slice
// ===========================================================================

test('mismatched >= + desc with limit offset throws unsupported to slice', function () {
    expect(fn () => TestRangeOrder::where(['created_at' => ['>=' => 100]])
        ->orderByDesc('created_at')->limit(2)->offset(1)->get())
        ->toThrow(RuntimeException::class, 'unsupported to slice');
});

test('mismatched > + desc with limit throws unsupported to slice', function () {
    expect(fn () => TestRangeOrder::where(['created_at' => ['>' => 100]])
        ->orderByDesc('created_at')->limit(2)->get())
        ->toThrow(RuntimeException::class, 'unsupported to slice');
});

test('mismatched < + asc with limit throws unsupported to slice', function () {
    expect(fn () => TestRangeOrder::where(['created_at' => ['<' => 400]])
        ->orderByAsc('created_at')->limit(2)->get())
        ->toThrow(RuntimeException::class, 'unsupported to slice');
});

test('mismatched <= + asc with offset throws unsupported to slice', function () {
    expect(fn () => TestRangeOrder::where(['created_at' => ['<=' => 300]])
        ->orderByAsc('created_at')->offset(1)->get())
        ->toThrow(RuntimeException::class, 'unsupported to slice');
});

// ===========================================================================
// 3. CALL - Builder passes one map, procedure does filtering
// ===========================================================================

test('call with range sends single map', function () {
    $rows = TestRangeOrder::call('list_orders')
        ->where(['created_at' => ['>=' => 300]])->get();

    expect(ids($rows))->toBe([3, 4, 5]);
});

test('call with limit and offset appends to same map', function () {
    $rows = TestRangeOrder::call('list_orders')
        ->where(['status' => 'active'])->limit(2)->offset(1)->get();

    // active = [1,2,4], offset 1 limit 2 = [2,4]
    expect(ids($rows))->toBe([2, 4]);
});

test('call where limit wins over builder limit', function () {
    $rows = TestRangeOrder::call('list_orders')
        ->where(['status' => 'active', 'limit' => 50])->limit(20)->get();

    expect(ids($rows))->toBe([1, 2, 4]);
});

test('call with first injects limit 1', function () {
    $row = TestRangeOrder::call('find_closest')
        ->where(['created_at' => 100])->first();

    expect($row)->not->toBeNull()
        ->and($row['id'])->toBe(1);
});

// ===========================================================================
// 4. COMBINATION
// ===========================================================================

test('combination range + call + orderby + limit + offset', function () {
    $rows = TestRangeOrder::call('list_orders')
        ->where(['created_at' => ['>=' => 100]])
        ->orderBy('created_at', 'desc')
        ->limit(2)->offset(1)->get();

    // all 5, desc = [5,4,3,2,1], offset 1 limit 2 = [4,3]
    // Call path is not affected by mismatched rule, Lua does slicing.
    expect(ids($rows))->toBe([4, 3]);
});

test('combination range + orderby + limit + offset non-call throws unsupported to slice', function () {
    // >= + desc + explicit limit/offset is mismatched + slicing, now throws by design.
    expect(fn () => TestRangeOrder::where(['created_at' => ['>=' => 100]])
        ->orderByDesc('created_at')->limit(2)->offset(1)->get())
        ->toThrow(RuntimeException::class, 'unsupported to slice');
});

test('combination range + orderby without slicing non-call works', function () {
    $rows = TestRangeOrder::where(['created_at' => ['>=' => 100]])
        ->orderByDesc('created_at')->get();

    expect(ids($rows))->toBe([5, 4, 3, 2, 1]);
});

// ===========================================================================
// 5. TWO RANGED SELECTS
// ===========================================================================

test('two ranged selects + orderby for call works', function () {
    $rows = TestRangeOrder::call('search_orders')
        ->where(['created_at' => ['>=' => 100], 'score' => ['<=' => 20]])
        ->orderBy('created_at', 'desc')->limit(10)->offset(0)->get();

    // matches [1,2], desc = [2,1]
    expect(ids($rows))->toBe([2, 1]);
});

test('two ranged selects + orderby asc + offset for call works', function () {
    $rows = TestRangeOrder::call('search_orders')
        ->where(['created_at' => ['>' => 100], 'score' => ['>=' => 20]])
        ->orderByAsc('created_at')->take(2)->skip(1)->get();

    // matches [2,3,4,5], asc, skip 1 take 2 = [3,4]
    expect(ids($rows))->toBe([3, 4]);
});

test('two ranged selects + orderby for non-call throws', function () {
    expect(fn () => TestRangeOrder::where(['created_at' => ['>=' => 100], 'score' => ['<=' => 20]])
        ->orderBy('created_at', 'desc')->limit(10)->get())
        ->toThrow(RuntimeException::class, 'Multiple where conditions');
});

test('two ranged selects + orderby asc + offset for non-call throws', function () {
    expect(fn () => TestRangeOrder::where(['created_at' => ['>' => 100], 'score' => ['>=' => 20]])
        ->orderByAsc('created_at')->take(2)->skip(1)->get())
        ->toThrow(RuntimeException::class, 'Multiple where conditions');
});