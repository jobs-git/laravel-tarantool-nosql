<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Tarantool\Client\Exception\RequestFailed;
use Tarantool\Nosql\Eloquent\Model;
use Tarantool\Nosql\Schema\Blueprint;
use Tarantool\Nosql\Schema\Schema;

class TestIndexedCustomer extends Model
{
    protected $table = 'test_indexed_customers';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    public array $schema = ['id', 'name', 'email', 'phone'];
}

beforeEach(function () {
    Capsule::connection()->evaluate("
        local space = box.space['app_test_indexed_customers']
        if space ~= nil then space:drop() end
    ");

    Schema::create('test_indexed_customers', function (Blueprint $table) {
        $table->id('id');
        $table->string('name');
        $table->string('email')->index()->nullable();
        $table->string('phone')->index()->nullable();
    });
});

afterEach(function () {
    Capsule::connection()->evaluate("
        local space = box.space['app_test_indexed_customers']
        if space ~= nil then space:drop() end
    ");
});

test('it can query records using secondary indexes on email and phone', function () {
    TestIndexedCustomer::create([
        'id' => 1001,
        'name' => 'Sarah Connor',
        'email' => 'sarah@resistance.org',
        'phone' => '+15550101',
    ]);

    TestIndexedCustomer::create([
        'id' => 1002,
        'name' => 'Kyle Reese',
        'email' => 'kyle@resistance.org',
        'phone' => '+15550102',
    ]);

    $byEmail = TestIndexedCustomer::where(['email' => 'kyle@resistance.org'])->first();
    $byPhone = TestIndexedCustomer::where(['phone' => '+15550101'])->first();

    expect($byEmail)->not->toBeNull()
        ->and($byEmail['id'])->toBe(1002)
        ->and($byEmail['name'])->toBe('Kyle Reese')
        ->and($byPhone)->not->toBeNull()
        ->and($byPhone['id'])->toBe(1001)
        ->and($byPhone['name'])->toBe('Sarah Connor');
});

test('it allows multiple null values on non-unique secondary indexes', function () {
    TestIndexedCustomer::create([
        'id' => 1003,
        'name' => 'No Email One',
        'email' => null,
        'phone' => '+15550103',
    ]);

    TestIndexedCustomer::create([
        'id' => 1004,
        'name' => 'No Email Two',
        'email' => null,
        'phone' => '+15550104',
    ]);

    $found = TestIndexedCustomer::where(['phone' => '+15550104'])->first();

    expect($found)->not->toBeNull()
        ->and($found['id'])->toBe(1004)
        ->and($found['email'])->toBeNull();
});

test('it returns null when secondary index lookup finds no matching record', function () {
    TestIndexedCustomer::create([
        'id' => 1005,
        'name' => 'John Connor',
        'email' => 'john@resistance.org',
        'phone' => '+15550105',
    ]);

    $missing = TestIndexedCustomer::where(['email' => 'unknown@skynet.org'])->first();

    expect($missing)->toBeNull();
});

test('it throws an error when querying a column without a defined Tarantool index', function () {
    TestIndexedCustomer::create([
        'id' => 1006,
        'name' => 'Marcus Wright',
        'email' => 'marcus@resistance.org',
        'phone' => '+15550106',
    ]);

    expect(fn () => TestIndexedCustomer::where(['name' => 'Marcus Wright'])->first())
        ->toThrow(RuntimeException::class, "No index 'name'");
});