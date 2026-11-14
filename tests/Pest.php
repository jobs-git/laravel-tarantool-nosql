<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Tarantool\Nosql\Connection;

// 1. Global configuration helper stub for standalone Capsule environment
// 1. Global configuration helper stub for standalone Capsule environment
if (!function_exists('config')) {
    function config($key = null, $default = null) {
        $config = [
            'database.default' => 'tarantool',
            'database.connections.tarantool' => [
                'driver'   => 'tarantool',
                'host'     => '127.0.0.1',
                'port'     => 3311,
                'username' => 'testuser',
                'password' => 'testpassword',
                'database' => 'app', // This is  $dbPrefix ('app')
            ],
            'database.connections.tarantool.database' => 'app',
        ];
        
        if ($key === null) {
            return $config;
        }
        
        return $config[$key] ?? $default;
    }
}

// 2. Global container helper stub for standalone Capsule environment
if (!function_exists('app')) {
    function app($abstract = null, array $parameters = []) {
        $container = \Illuminate\Container\Container::getInstance();
        
        if ($abstract === null) {
            return $container;
        }

        if ($abstract === 'tarantool.connection.tarantool' || $abstract === 'db.connection') {
            return Capsule::connection();
        }
        
        if ($container->bound($abstract)) {
            return $container->make($abstract, $parameters);
        }

        return $container;
    }
}

// 3. Initialize Capsule globally during bootstrap
$capsule = new Capsule;

$capsule->getDatabaseManager()->extend('tarantool', function ($config, $name) {
    return new Connection($config);
});

if (!Connection::hasMacro('evaluate')) {
    Connection::macro('evaluate', function (string $expression) {
        return $this->client->evaluate($expression);
    });
}

$capsule->addConnection([
    'driver'    => 'tarantool',
    'host'      => '127.0.0.1',
    'port'      => 3311,
    'username'  => 'testuser',
    'password'  => 'testpassword',
    'database'  => 'app',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();