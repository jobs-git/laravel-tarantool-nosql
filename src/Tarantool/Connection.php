<?php

namespace Tarantool\Nosql;

use Illuminate\Database\Connection as BaseConnection;
use Tarantool\Client\Client;
use Tarantool\Nosql\Query\Builder;

class Connection extends BaseConnection
{
    protected ?Client $client = null;

    public function __construct(array $config, $name = '')
    {
        parent::__construct(null, $name, '', $config);
        
        $this->client = Client::fromOptions([
            'uri' => "tcp://{$config['host']}:{$config['port']}",
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
        ]);
    }

    public function getTarantoolClient(): Client
    {
        return $this->client;
    }

    public function getPdo() { return null; }
    public function getReadPdo() { return null; }

    /**
     * Return our custom Tarantool query builder instance.
     */
    public function table($table, $as = null, string $primaryKey = 'id', array $schema = [])
    {
        $database = $this->getConfig('database') ?? '';
        
        // Enforce idempotent prefixing here at the connection level
        $spaceName = $table;
        if ($database) {
            $prefixString = "{$database}_";
            $unprefixed = str_starts_with($table, $prefixString) ? substr($table, strlen($prefixString)) : $table;
            $spaceName = "{$prefixString}{$unprefixed}";
        }

        return new Builder($this, $spaceName, $primaryKey, $schema);
    }
}