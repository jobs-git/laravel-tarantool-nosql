<?php

namespace Tarantool\Nosql\Query;

use Illuminate\Database\Query\Processors\Processor as BaseProcessor;

class Processor extends BaseProcessor
{
    public function processSelect($query, $results)
    {
        return $results;
    }
}