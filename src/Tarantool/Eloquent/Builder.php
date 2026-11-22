<?php

namespace Tarantool\Nosql\Eloquent;

use Illuminate\Database\Eloquent\Builder as BaseBuilder;

class Builder extends BaseBuilder
{
    public function call(string $procedure)
    {
        $this->getQuery()->call($procedure);
        return $this;
    }

    public function get($columns = ['*'])
    {
        // Procedure path returns ReadOnlyRecord / Collection, not Eloquent models
        if (method_exists($this->getQuery(), 'hasProcedure') && $this->getQuery()->hasProcedure()) {
            return $this->getQuery()->get();
        }
        return parent::get($columns);
    }

    public function first($columns = ['*'])
    {
        if (method_exists($this->getQuery(), 'hasProcedure') && $this->getQuery()->hasProcedure()) {
            return $this->getQuery()->first();
        }
        return parent::first($columns);
    }

    public function update(array $values)
    {
        if (method_exists($this->getQuery(), 'hasProcedure') && $this->getQuery()->hasProcedure()) {
            return $this->getQuery()->update($values);
        }
        return parent::update($values);
    }
}