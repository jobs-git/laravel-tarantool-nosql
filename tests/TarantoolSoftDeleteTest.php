<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tarantool\Nosql\Eloquent\Model;
use Tarantool\Nosql\Schema\Schema;
use Tarantool\Nosql\Schema\Blueprint;

class SoftDeleteTestUser extends Model {
    use SoftDeletes;

    protected $table = 'soft_users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    // Strict schema must include 'deleted_at' when using SoftDeletes
    public array $schema = ['id', 'name', 'email', 'deleted_at'];
}

class InvalidSoftDeleteTestUser extends Model {
    use SoftDeletes;

    protected $table = 'invalid_soft_users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    // Intentionally missing 'deleted_at' to trigger validation failure
    public array $schema = ['id', 'name', 'email'];
}

beforeEach(function () {
    Capsule::connection()->evaluate("
        local space = box.space['app_soft_users']
        if space ~= nil then space:drop() end
        local space2 = box.space['app_invalid_soft_users']
        if space2 ~= nil then space2:drop() end
    ");

    Schema::create('soft_users', function (Blueprint $table) {
        $table->id('id');
        $table->string('name');
        $table->string('email')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
});

test('it throws an exception if SoftDeletes is used but deleted_at is missing from schema', function () {
    expect(function () {
        InvalidSoftDeleteTestUser::where(['id' => 1])->first();
    })->toThrow(\RuntimeException::class, "uses SoftDeletes, but the column 'deleted_at' is missing from the explicit \$schema array");
});

test('it passes validation when SoftDeletes and deleted_at are both present in schema', function () {
    SoftDeleteTestUser::create([
        'id' => 301,
        'name' => 'Sarah Connor',
        'email' => 'sarah@resistance.org',
        'deleted_at' => null
    ]);

    $user = SoftDeleteTestUser::where(['id' => 301])->first();

    expect($user['name'])->toBe('Sarah Connor');
    expect($user['deleted_at'])->toBeNull();
});

test('it blocks direct instance deletion and enforces static deletion', function () {
    // Create an instance directly or fetch it if hydrated as an object
    // Since instances are immutable data bags, direct method calls on them should throw:
    $userInstance = new SoftDeleteTestUser();

    expect(fn () => $userInstance->delete())
        ->toThrow(\BadMethodCallException::class, "Model instances are immutable");
});