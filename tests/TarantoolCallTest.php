<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Tarantool\Nosql\Eloquent\Model;

// Eloquent model - Tarantool\Nosql\Model extends Illuminate\Database\Eloquent\Model
class CallTestUser extends Model
{
    protected $table = 'test_call_users';
    protected $primaryKey = 'id';
    protected $connection = 'tarantool';
    public $timestamps = false;
    protected $guarded = [];
    public $schema = ['id', 'name', 'status'];
}

beforeEach(function () {
    $client = Capsule::connection()->getTarantoolClient();

    $client->evaluate("
        if box.space.app_test_call_users ~= nil then
            box.space.app_test_call_users:drop()
        end
        box.schema.func.drop('test_get_user', {if_exists = true})
        box.schema.func.drop('test_echo_params', {if_exists = true})
        box.schema.func.drop('test_no_params', {if_exists = true})

        local space = box.schema.space.create('app_test_call_users', {if_not_exists = true})
        space:format({
            {name = 'id', type = 'unsigned'},
            {name = 'name', type = 'string'},
            {name = 'status', type = 'string'},
        })
        space:create_index('primary', {parts = {'id'}, if_not_exists = true})

        space:insert({1, 'James', 'active'})
        space:insert({2, 'Maria', 'pending'})

        -- MAP style: function(params) with params.id, params.param0, etc.
        box.schema.func.create('test_get_user', {
            if_not_exists = true,
            is_sandboxed = false,
            body = [[function(params)
                local id = params.id
                if id == nil then return nil end
                local t = box.space.app_test_call_users:get(id)
                if t == nil then return nil end
                return {id = t[1], name = t[2], status = t[3]}
            end]]
        })

        box.schema.func.create('test_echo_params', {
            if_not_exists = true,
            is_sandboxed = false,
            body = [[function(params)
                return {param0 = params.param0, param1 = params.param1}
            end]]
        })

        box.schema.func.create('test_no_params', {
            if_not_exists = true,
            is_sandboxed = false,
            body = [[function(params)
                return {msg = 'hello'}
            end]]
        })
    ");
});

afterEach(function () {
    $client = Capsule::connection()->getTarantoolClient();
    $client->evaluate("
        if box.space.app_test_call_users ~= nil then
            box.space.app_test_call_users:drop()
        end
        box.schema.func.drop('test_get_user', {if_exists = true})
        box.schema.func.drop('test_echo_params', {if_exists = true})
        box.schema.func.drop('test_no_params', {if_exists = true})
    ");
});

it('where->call returns a Collection of ReadOnlyRecord with param-name map', function () {
    // where() = map, call() = procedure name only
    $rows = CallTestUser::where(['param0' => 'a', 'param1' => 'b'])->call('test_echo_params');

    expect($rows)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($rows->count())->toBe(1);
    expect($rows[0]['param0'])->toBe('a');
    expect($rows[0]['param1'])->toBe('b');
});

it('where->call->first returns the first row right away', function () {
    $row = CallTestUser::where(['id' => 1])->call('test_get_user')->first();

    expect($row)->not->toBeNull();
    expect($row['id'])->toBe(1);
    expect($row['name'])->toBe('James');
    expect($row['status'])->toBe('active');
});

it('where->call->first returns null when procedure returns empty', function () {
    $row = CallTestUser::where(['id' => 999])->call('test_get_user')->first();

    expect($row)->toBeNull();
});

it('where->first still uses Builder::first with select', function () {
    $row = CallTestUser::where(['id' => 2])->first();

    expect($row)->not->toBeNull();
    expect($row['name'])->toBe('Maria');
});

it('where empty ->call works for no-param procedures', function () {
    $row = CallTestUser::where([])->call('test_no_params')->first();

    expect($row)->not->toBeNull();
    expect($row['msg'])->toBe('hello');
});