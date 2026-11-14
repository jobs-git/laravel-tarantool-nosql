<?php

namespace Tarantool\Nosql;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register()
    {
        // 1. Bind the low-level Tarantool client singleton
        $this->app->singleton('tarantool.connection.tarantool', function ($app) {
            $config = $app['config']['database.connections.tarantool'] ?? [];
            
            return \Tarantool\Client\Client::fromOptions([
                'uri'      => "tcp://" . ($config['host'] ?? '127.0.0.1') . ":" . ($config['port'] ?? 3301),
                'username' => $config['username'] ?? 'admin',
                'password' => $config['password'] ?? '',
            ]);
        });

        // 2. Register 'tarantool' as a native driver in Laravel's DatabaseManager early
        $this->app->resolving('db', function ($db) {
            $db->extend('tarantool', function ($config, $name) {
                $config['name'] = $name;
                return new Connection($config, $name);
            });
        });
    }

    public function boot()
    {
        // Package booting tasks (if any) go here
    }
}