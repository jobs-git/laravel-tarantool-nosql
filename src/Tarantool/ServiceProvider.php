<?php

namespace Tarantool\Nosql;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Tarantool\Nosql\Console\Commands\Migrate;
use Tarantool\Client\Client;

class ServiceProvider extends BaseServiceProvider
{
    public function register()
    {
        // Dynamically register container bindings for ALL configured Tarantool connections
        $connections = array_keys(config('database.connections', []));

        foreach ($connections as $name) {
            $driver = config("database.connections.{$name}.driver");

            if ($driver === 'tarantool') {
                $this->app->singleton("tarantool.connection.{$name}", function ($app) use ($name) {
                    $config = $app['config']["database.connections.{$name}"] ?? [];
                    $host = $config['host'] ?? '127.0.0.1';

                    // Determine URI based on TCP vs Unix socket format
                    if (str_starts_with($host, 'unix://')) {
                        $uri = $host;
                    } elseif (str_starts_with($host, '/')) {
                        $uri = 'unix://' . $host;
                    } elseif (str_starts_with($host, 'unix/')) {
                        $uri = 'unix:///' . ltrim(str_replace('unix/', '', $host), '/');
                    } else {
                        $uri = "tcp://{$host}:" . ($config['port'] ?? 3301);
                    }
                    
                    return Client::fromOptions([
                        'uri'      => $uri,
                        'username' => $config['username'] ?? 'admin',
                        'password' => $config['password'] ?? '',
                    ]);
                });
            }
        }

        // Register 'tarantool' as a native driver in Laravel's DatabaseManager
        $this->app->resolving('db', function ($db) {
            $db->extend('tarantool', function ($config, $name) {
                $config['name'] = $name;
                return new Connection($config, $name);
            });
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Migrate::class,
            ]);
        }
    }
}