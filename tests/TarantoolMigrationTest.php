<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Tarantool\Nosql\Schema\Schema;
use Tarantool\Nosql\Schema\Blueprint;

// Define a sample migration class representing a user profile update
class CreateUserProfilesTable {
    public function up()
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id('id');
            $table->string('bio')->nullable();
            $table->integer('age')->default(18);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_profiles');
    }
}

beforeEach(function () {
    // Clean up before each test
    Schema::dropIfExists('user_profiles');
});

test('it can run migration up and create spaces', function () {
    $migration = new CreateUserProfilesTable();
    $migration->up();

    // Verify space was successfully created in Tarantool
    $engineResult = Capsule::connection()->evaluate("
        local space = box.space.app_user_profiles
        return space and space.engine or nil
    ");

    $engine = is_array($engineResult) ? ($engineResult[0] ?? null) : $engineResult;
    expect($engine)->not()->toBeNull();
});

test('it can run migration down and drop spaces', function () {
    $migration = new CreateUserProfilesTable();
    $migration->up();
    
    $migration->down();

    $existsResult = Capsule::connection()->evaluate("
        if box.space.app_user_profiles == nil then
            return true
        else
            return false
        end
    ");

    $exists = is_array($existsResult) ? ($existsResult[0] ?? $existsResult) : $existsResult;
    expect((bool)$exists)->toBeTrue();
});