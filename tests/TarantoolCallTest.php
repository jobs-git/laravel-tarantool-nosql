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
        box.schema.func.drop('test_update_user', {if_exists = true})
        box.schema.func.drop('test_wrong_read', {if_exists = true})
        box.schema.func.drop('test_wrong_update', {if_exists = true})

        local space = box.schema.space.create('app_test_call_users', {if_not_exists = true})
        space:format({
            {name = 'id', type = 'unsigned'},
            {name = 'name', type = 'string'},
            {name = 'status', type = 'string'},
        })
        space:create_index('primary', {parts = {'id'}, if_not_exists = true})

        space:insert({1, 'James', 'active'})
        space:insert({2, 'Maria', 'pending'})

        -- READ: 1 map -> function(params)
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

        -- WRITE: 2 maps -> function(search, updates)
        box.schema.func.create('test_update_user', {
            if_not_exists = true,
            is_sandboxed = false,
            body = [[function(search, updates)
                local id = search.id
                if id == nil then return nil end
                local t = box.space.app_test_call_users:get(id)
                if t == nil then return nil end
                local new_name = updates.name or t[2]
                local new_status = updates.status or t[3]
                local updated = box.space.app_test_call_users:update(id, {
                    {'=', 2, new_name},
                    {'=', 3, new_status},
                })
                if updated == nil then return nil end
                return {id = updated[1], name = updated[2], status = updated[3]}
            end]]
        })

        -- WRONG FORMATS for validation tests
        -- Read should be 1 param, this has 2
        box.schema.func.create('test_wrong_read', {
            if_not_exists = true,
            is_sandboxed = false,
            body = [[function(a, b)
                return {msg = 'wrong'}
            end]]
        })

        -- Update should be 2 params, this has 1
        box.schema.func.create('test_wrong_update', {
            if_not_exists = true,
            is_sandboxed = false,
            body = [[function(params)
                return {msg = 'wrong'}
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
        box.schema.func.drop('test_update_user', {if_exists = true})
        box.schema.func.drop('test_wrong_read', {if_exists = true})
        box.schema.func.drop('test_wrong_update', {if_exists = true})
    ");
});

it('call->where->first returns the first row', function () {
    $row = CallTestUser::call('test_get_user')->where(['id' => 1])->first();

    expect($row)->not->toBeNull();
    expect($row['id'])->toBe(1);
    expect($row['name'])->toBe('James');
    expect($row['status'])->toBe('active');
});

it('call->where->get returns a Collection with param-name map', function () {
    $rows = CallTestUser::call('test_echo_params')->where(['param0' => 'a', 'param1' => 'b'])->get();

    expect($rows)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($rows->count())->toBe(1);
    expect($rows[0]['param0'])->toBe('a');
    expect($rows[0]['param1'])->toBe('b');
});

it('call->where->first returns null when procedure returns empty', function () {
    $row = CallTestUser::call('test_get_user')->where(['id' => 999])->first();

    expect($row)->toBeNull();
});

it('where->first still uses Builder::first with select', function () {
    $row = CallTestUser::where(['id' => 2])->first();

    expect($row)->not->toBeNull();
    expect($row['name'])->toBe('Maria');
});

it('call->where empty works for no-param procedures', function () {
    $row = CallTestUser::call('test_no_params')->where([])->first();

    expect($row)->not->toBeNull();
    expect($row['msg'])->toBe('hello');
});

it('on->call->where->first works', function () {
    $row = CallTestUser::on('tarantool')->call('test_get_user')->where(['id' => 1])->first();

    expect($row)->not->toBeNull();
    expect($row['name'])->toBe('James');
});

it('call->where->update returns updated record with 2 maps', function () {
    $row = CallTestUser::call('test_update_user')
        ->where(['id' => 1])
        ->update(['status' => 'inactive']);

    expect($row)->not->toBeNull();
    expect($row['id'])->toBe(1);
    expect($row['name'])->toBe('James');
    expect($row['status'])->toBe('inactive');

    // Verify it really updated in Tarantool
    $check = CallTestUser::where(['id' => 1])->first();
    expect($check['status'])->toBe('inactive');
});

it('call->where->first errors out if procedure has wrong format', function () {
    // test_wrong_read has 2 params, read expects 1 -> function(params)
    CallTestUser::call('test_wrong_read')->where(['id' => 1])->first();
})->throws(\RuntimeException::class, 'wrong format');

it('call->where->update errors out if procedure has wrong format', function () {
    // test_wrong_update has 1 param, update expects 2 -> function(search, updates)
    CallTestUser::call('test_wrong_update')->where(['id' => 1])->update(['status' => 'inactive']);
})->throws(\RuntimeException::class, 'wrong format');