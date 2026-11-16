<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Tarantool\Nosql\Traits\HasTarantool;
use Tarantool\Nosql\Schema\Schema;
use Tarantool\Nosql\Schema\Blueprint;

class TestTraitUser extends Model {

    use HasTarantool;

    protected $table = 'users2';
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    // Strict explicit schema (Zero-Introspection)
    public array $schema = ['id', 'name', 'email'];
}

beforeEach(function () {
    Capsule::connection()->evaluate("
        local space = box.space['app_users2']
        if space ~= nil then space:drop() end
    ");

    Schema::create('users2', function (Blueprint $table) {
        $table->id('id');
        $table->string('name');
        $table->string('email')->nullable();
    });
});

test('it allows safe in-memory hydration on model instances without hitting a database', function () {
    $instance = new TestTraitUser();

    $hydrated = $instance->hydrate([
        ['id' => 601, 'name' => 'Kyle Reese', 'email' => 'kyle@resistance.org'],
        ['id' => 602, 'name' => 'T-800', 'email' => 'null'],
    ]);

    expect($hydrated)->toHaveCount(2)
        ->and($hydrated[0])->toBeInstanceOf(TestTraitUser::class)
        ->and($hydrated[0]->name)->toBe('Kyle Reese')
        ->and($hydrated[1]->email)->toBeNull(); // also verifies 'null' string normalization
});

test('it blocks dynamic query methods on model instances to prevent hitting another db', function () {
    $instance = new TestTraitUser();

    expect(fn () => $instance->where(['id' => 501]))
        ->toThrow(BadMethodCallException::class, 'cannot be called dynamically or on an instance');

    expect(fn () => $instance->first())
        ->toThrow(BadMethodCallException::class, 'cannot be called dynamically or on an instance');

    expect(fn () => $instance->get())
        ->toThrow(BadMethodCallException::class, 'cannot be called dynamically or on an instance');
});

test('it enforces model instance immutability for save, update, and delete', function () {
    $instance = (new TestTraitUser())->newFromBuilder([
        'id' => 603,
        'name' => 'Miles Dyson',
        'email' => 'miles@cyberdyne.com',
    ]);

    expect(fn () => $instance->save())
        ->toThrow(BadMethodCallException::class, 'Model instances are immutable');

    expect(fn () => $instance->update(['name' => 'Changed']))
        ->toThrow(BadMethodCallException::class, 'Model instances are immutable');

    expect(fn () => $instance->delete())
        ->toThrow(BadMethodCallException::class, 'Model instances are immutable');
});