<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Tarantool\Nosql\Schema\Schema;
use Tarantool\Nosql\Schema\Blueprint;

test('it creates vinyl spaces in an isolated ephemeral directory', function () {
    $spaceName = 'vinyl_test_space';
    
    Schema::create($spaceName, function (Blueprint $table) {
        $table->id('id');
        $table->string('name')->nullable();
    });

    $engineResult = Capsule::connection()->evaluate("
        local space = box.space.app_$spaceName
        return space and space.engine or nil
    ");

    $engine = is_array($engineResult) ? ($engineResult[0] ?? null) : $engineResult;

    expect($engine)->toBe('vinyl');
});