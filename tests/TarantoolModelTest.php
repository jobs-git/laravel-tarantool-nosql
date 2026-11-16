<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Tarantool\Nosql\Schema\Schema;
use Tarantool\Nosql\Schema\Blueprint;

use Tarantool\Nosql\Eloquent\Model;

class TestUser extends Model {

    protected $table = 'users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    // Strict explicit schema (Zero-Introspection)
    public array $schema = ['id', 'name', 'email'];
}

beforeEach(function () {
    Capsule::connection()->evaluate("
        local space = box.space['app_users']
        if space ~= nil then space:drop() end
    ");

    Schema::create('users', function (Blueprint $table) {
        $table->id('id');
        $table->string('name');
        $table->string('email')->nullable();
    });
});

test('it can create and fetch records via explicit-schema model', function () {
    TestUser::create([
        'id' => 501,
        'name' => 'Sarah Connor',
        'email' => 'sarah@resistance.org'
    ]);

    $user = TestUser::where(
        ['id' => 501]
        )->first();

    expect($user['name'])->toBe('Sarah Connor');
    expect($user['email'])->toBe('sarah@resistance.org');
});

test('it can update records via explicit-schema model', function () {
    // Create initial record
    TestUser::create([
        'id' => 502,
        'name' => 'John Connor',
        'email' => 'john@resistance.org'
    ]);

    // Perform static update via fluent query builder
    TestUser::where(
        ['id' => 502]
        )->update([
        'name' => 'John T. Connor'
    ]);

    $updated = TestUser::where(
        ['id' => 502]
        )->first();
    expect($updated['name'])->toBe('John T. Connor');
});




test('it handles limit correctly with contiguous primary IDs', function () {
    // Create records with contiguous primary IDs
    TestUser::create(['id' => 101, 'name' => 'User One', 'email' => 'one@test.org']);
    TestUser::create(['id' => 102, 'name' => 'User Two', 'email' => 'two@test.org']);
    TestUser::create(['id' => 103, 'name' => 'User Three', 'email' => 'three@test.org']);

    // Query with a limit to fetch a subset of contiguous records from the primary index
    $users = TestUser::limit(2)->get();

    expect($users)->toBeArray();
    expect(count($users))->toBe(2);
    expect($users[0]['id'])->toBe(101);
    expect($users[1]['id'])->toBe(102);
});

test('it handles offset correctly for pagination and skipping', function () {
    // Create sequential records
    TestUser::create(['id' => 201, 'name' => 'Page One', 'email' => 'p1@test.org']);
    TestUser::create(['id' => 202, 'name' => 'Page Two', 'email' => 'p2@test.org']);
    TestUser::create(['id' => 203, 'name' => 'Page Three', 'email' => 'p3@test.org']);

    // Skip the first record and take the next 2
    $users = TestUser::offset(1)
        ->limit(2)
        ->get();

    expect($users)->toBeArray();
    expect(count($users))->toBe(2);
    expect($users[0]['id'])->toBe(202);
    expect($users[1]['id'])->toBe(203);
});

test('it handles limit seamlessly when resulting primary IDs are non-contiguous', function () {
    // Create records with widely spaced, non-contiguous primary IDs (300, 750, 1500)
    TestUser::create(['id' => 300, 'name' => 'First Non-Contig', 'email' => 'nc1@test.org']);
    TestUser::create(['id' => 750, 'name' => 'Second Non-Contig', 'email' => 'nc2@test.org']);
    TestUser::create(['id' => 1500, 'name' => 'Third Non-Contig', 'email' => 'nc3@test.org']);

    // Query with a limit (Tarantool iterates the primary index sequentially)
    $users = TestUser::limit(2)->get();

    expect($users)->toBeArray();
    expect(count($users))->toBe(2);
    
    // Verify the primary IDs retrieved are non-contiguous but correctly sliced by the limit
    expect($users[0]['id'])->toBe(300);
    expect($users[1]['id'])->toBe(750);
});






test('it can query records using greater than or equal operator (lower boundary inclusive, forward scan)', function () {
    TestUser::create(['id' => 601, 'name' => 'Alice', 'email' => 'alice@test.org']);
    TestUser::create(['id' => 602, 'name' => 'Bob', 'email' => 'bob@test.org']);
    TestUser::create(['id' => 603, 'name' => 'Charlie', 'email' => 'charlie@test.org']);

    // id >= 602 scans forward: returns 602, then 603
    $results = TestUser::where(['id' => ['>=' => 602]])
        ->limit(10)
        ->get();

    expect($results)->toHaveCount(2);
    expect($results[0]['id'])->toBe(602); // Boundary included (first)
    expect($results[1]['id'])->toBe(603);
});

test('it can query records using less than or equal operator (upper boundary inclusive, backward scan)', function () {
    TestUser::create(['id' => 701, 'name' => 'Dave', 'email' => 'dave@test.org']);
    TestUser::create(['id' => 702, 'name' => 'Eve', 'email' => 'eve@test.org']);
    TestUser::create(['id' => 703, 'name' => 'Frank', 'email' => 'frank@test.org']);

    // id <= 702 scans backward: returns 702 (boundary), then 701
    $results = TestUser::where(['id' => ['<=' => 702]])
        ->limit(10)
        ->get();

    expect($results)->toHaveCount(2);
    expect($results[0]['id'])->toBe(702); // Boundary included (scans backward from 702)
    expect($results[1]['id'])->toBe(701);
});



// test if errors are executed


test('it throws an exception when where clause uses positional arguments instead of an array', function () {
    // This should fail because it violates  strict associative-array standard
    expect(function () {
        TestUser::where('id', 501)->first();
    })->toThrow(\InvalidArgumentException::class, "Tarantool strict standard enforced");
});

test('it throws an exception when attempting to chain multiple where clauses', function () {
    expect(function () {
        TestUser::where(['id' => 501])->where(['name' => 'Sarah Connor'])->get();
    })->toThrow(\BadMethodCallException::class);
});

test('it throws an error when attempting update->where instead of where->update', function () {
    expect(function () {
        // update() returns a boolean, so calling ->where() on it triggers a PHP error
        TestUser::update(['name' => 'Invalid'])->where(['id' => 501]);
    })->toThrow(\Error::class);
});

test('it can fetch multiple records using an array list of IDs', function () {
    // Create multiple test records
    TestUser::create([
        'id' => 601,
        'name' => 'Kyle Reese',
        'email' => 'kyle@resistance.org'
    ]);

    TestUser::create([
        'id' => 602,
        'name' => 'John Connor',
        'email' => 'john@resistance.org'
    ]);

    TestUser::create([
        'id' => 603,
        'name' => 'Sarah Connor',
        'email' => 'sarah@resistance.org'
    ]);

    expect(fn () => TestUser::where(['id' => [601, 603]])->get())
        ->toThrow(\RuntimeException::class, "Multi-ID / array-based lookups are strictly prohibited");
});


test('it throws an exception when attempting to update using any range operator', function () {
    TestUser::create(['id' => 801, 'name' => 'Grace', 'email' => 'grace@test.org']);

    expect(fn () => TestUser::where(['id' => ['>=' => 801]])->update(['name' => 'Blocked']))
        ->toThrow(\RuntimeException::class, 'Updates can only be performed using exact-match equality');

    expect(fn () => TestUser::where(['id' => ['<=' => 801]])->update(['name' => 'Blocked']))
        ->toThrow(\RuntimeException::class, 'Updates can only be performed using exact-match equality');
});

use DateTimeImmutable;

class TestUserWithTimestamp extends Model {
    protected $table = 'users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    public array $schema = ['id', 'name', 'email', 'created_at'];
}

test('it can store and retrieve native datetime values seamlessly', function () {
    Capsule::connection()->evaluate("
        local space = box.space['app_users']
        if space ~= nil then space:drop() end
    ");

    Schema::create('users', function (Blueprint $table) {
        $table->id('id');
        $table->string('name');
        $table->string('email')->nullable();
        $table->timestamp('created_at')->nullable(); // Changed from datetime() to timestamp()
    });

    $now = new DateTimeImmutable('2026-06-06 12:00:00');

    TestUserWithTimestamp::create([
        'id' => 901,
        'name' => 'Terminator',
        'email' => 't800@skynet.org',
        'created_at' => $now,
    ]);

    $user = TestUserWithTimestamp::where(['id' => 901])->first();

    expect($user['name'])->toBe('Terminator');
    expect($user['created_at'])->toBeInstanceOf(DateTimeImmutable::class);
    expect($user['created_at']->format('Y-m-d H:i:s'))->toBe('2026-06-06 12:00:00');
});


test('it can read record attributes via both object property (->) and array key ([]) syntax', function () {
    $created = TestUser::create([
        'id' => 1101,
        'name' => 'Sarah Connor',
        'email' => 'sarah@resistance.org',
    ]);

    $fetched = TestUser::where(['id' => 1101])->first();

    // Read via object property (->)
    expect($created->id)->toBe(1101)
        ->and($fetched->id)->toBe(1101)
        ->and($fetched->name)->toBe('Sarah Connor')
        ->and($fetched->email)->toBe('sarah@resistance.org')
        // Read via array key ([])
        ->and($fetched['id'])->toBe(1101)
        ->and($fetched['name'])->toBe('Sarah Connor')
        ->and($fetched['email'])->toBe('sarah@resistance.org');
});

test('it throws an error when attempting assignment on a returned record', function () {
    TestUser::create([
        'id' => 1102,
        'name' => 'John Connor',
        'email' => 'john@resistance.org',
    ]);

    $user = TestUser::where(['id' => 1102])->first();

    // Block existing column assignment ($user->name = val)
    expect(fn () => $user->name = 'Mutated Name')
        ->toThrow(\RuntimeException::class, 'Returned records are read-only');

    // Block arbitrary property assignment ($user->asdfasdf = val)
    expect(fn () => $user->asdfasdf = 'val')
        ->toThrow(\RuntimeException::class, 'Returned records are read-only');

    // Block array key assignment ($user['name'] = val)
    expect(fn () => $user['name'] = 'Mutated Name')
        ->toThrow(\RuntimeException::class, 'Returned records are read-only');
});