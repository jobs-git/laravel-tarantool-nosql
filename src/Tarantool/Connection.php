<?php

namespace Tarantool\Nosql;

use Illuminate\Database\Connection as BaseConnection;
use Tarantool\Client\Client;
use Tarantool\Client\Packer\PurePacker;
use Tarantool\Nosql\Support\DateTimeExtension;
use Tarantool\Nosql\Query\Builder;

class Connection extends BaseConnection
{
    protected ?Client $client = null;

    public function __construct(array $config, $name = '')
    {
        parent::__construct(null, $name, '', $config);

        $uri = "tcp://" . ($config['host'] ?? '127.0.0.1') . ":" . ($config['port'] ?? 3301);

        $packer = PurePacker::fromExtensions(new DateTimeExtension());

        $this->client = Client::fromOptions([
            'uri'      => $uri,
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
        ], $packer);
    }

    public function getTarantoolClient(): Client
    {
        return $this->client;
    }

    public function evaluate(string $expression, array $parameters = [])
    {
        return $this->client->evaluate($expression, ...$parameters);
    }

    public function getPdo() { return null; }
    public function getReadPdo() { return null; }

    public function table($table, $as = null, string $primaryKey = 'id', array $schema = [])
    {
        $database = $this->getConfig('database') ?? '';

        $spaceName = $table;
        if ($database) {
            $prefixString = "{$database}_";
            $unprefixed = str_starts_with($table, $prefixString) ? substr($table, strlen($prefixString)) : $table;
            $spaceName = "{$prefixString}{$unprefixed}";
        }

        return new Builder($this, $spaceName, $primaryKey, $schema);
    }
}