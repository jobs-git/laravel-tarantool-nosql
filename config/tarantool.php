<?php

return [
    'tarantool' => [
        'driver'   => 'tarantool',
        'database' => env('TARANTOOL_DATABASE', ''),
        'host'     => env('TARANTOOL_HOST', '127.0.0.1'),
        'port'     => env('TARANTOOL_PORT', 3301),
        'username' => env('TARANTOOL_USERNAME', 'admin'),
        'password' => env('TARANTOOL_PASSWORD', ''),
    ],
];