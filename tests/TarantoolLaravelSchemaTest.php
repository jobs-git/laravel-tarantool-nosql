<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint as LaravelBlueprint;

beforeEach(function () {
    Capsule::connection()->getSchemaBuilder()->dropIfExists('laravel_profiles');
});

test('it can create space via Laravel Schema Builder', function () {
    Capsule::connection()->getSchemaBuilder()->create('laravel_profiles', function (LaravelBlueprint $table) {
        $table->id('id');
        $table->string('bio')->nullable();
        $table->integer('age')->default(18);
    });

    $result = Capsule::connection()->evaluate("return box.space['app_laravel_profiles'] ~= nil");
    $exists = is_array($result) ? ($result[0] ?? false) : $result;

    expect($exists)->toBeTrue();
});

test('it creates correct format via Laravel Schema Builder', function () {
    Capsule::connection()->getSchemaBuilder()->create('laravel_profiles', function (LaravelBlueprint $table) {
        $table->id('id');
        $table->string('bio')->nullable();
        $table->integer('age')->default(18);
    });

    $result = Capsule::connection()->evaluate("return box.space['app_laravel_profiles']:format()");
    $format = is_array($result) ? ($result[0] ?? []) : $result;

    $names = collect($format)->pluck('name')->toArray();

    expect($names)->toContain('id', 'bio', 'age');
    expect(collect($format)->firstWhere('name', 'id')['type'])->toBe('unsigned');
    expect(collect($format)->firstWhere('name', 'bio')['is_nullable'])->toBeTrue();
});

test('it creates primary index with sequence for id() via Laravel Schema', function () {
    Capsule::connection()->getSchemaBuilder()->create('laravel_profiles', function (LaravelBlueprint $table) {
        $table->id('id');
        $table->string('bio')->nullable();
    });

    $result = Capsule::connection()->evaluate("
        local s = box.space['app_laravel_profiles']
        if s == nil or s.index.primary == nil then return false end
        if s.index.primary.sequence_id ~= nil then return true end
        return box.space._space_sequence:get(s.id) ~= nil
    ");

    $hasSequence = is_array($result) ? ($result[0] ?? false) : $result;

    expect($hasSequence)->toBeTrue();
});

test('it can drop space via Laravel Schema Builder', function () {
    Capsule::connection()->getSchemaBuilder()->create('laravel_profiles', function (LaravelBlueprint $table) {
        $table->id('id');
        $table->string('bio')->nullable();
    });

    Capsule::connection()->getSchemaBuilder()->dropIfExists('laravel_profiles');

    $result = Capsule::connection()->evaluate("return box.space['app_laravel_profiles'] == nil");
    $gone = is_array($result) ? ($result[0] ?? false) : $result;

    expect($gone)->toBeTrue();
});