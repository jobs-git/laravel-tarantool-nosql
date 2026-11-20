<?php

namespace Tarantool\Nosql\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar as BaseGrammar;
use Illuminate\Support\Fluent;

class Grammar extends BaseGrammar
{
    protected function spaceName(Blueprint $blueprint): string
    {
        $database = config('database.connections.tarantool.database', '');
        $table = $blueprint->getTable();

        if ($database && ! str_starts_with($table, $database . '_')) {
            return $database . '_' . $table;
        }

        return $table;
    }

    protected function typeFor(Fluent $column): string
    {
        if ($column->get('unsigned')) {
            return 'unsigned';
        }

        return match ($column->get('type')) {
            'string', 'char', 'text' => 'string',
            'integer', 'bigInteger', 'smallInteger', 'mediumInteger', 'tinyInteger' => 'integer',
            'unsignedInteger', 'unsignedBigInteger' => 'unsigned',
            'boolean' => 'boolean',
            'dateTime', 'date', 'timestamp' => 'datetime',
            'decimal', 'double', 'float' => 'number',
            default => 'string',
        };
    }


    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        $space = $this->spaceName($blueprint);
        $columns = $blueprint->getColumns();

        $indexColumns = (array) $command->columns;

        // Single column -> index name must be the column name for Builder::where('email')
        // Composite -> keep Laravel's generated name
        // Explicit custom name like $table->index('email', 'my_idx') -> keep it
        $indexName = $command->index;
        $autoName = $blueprint->getTable() . '_' . implode('_', $indexColumns) . '_index';
        $autoUniqueName = $blueprint->getTable() . '_' . implode('_', $indexColumns) . '_unique';

        if (count($indexColumns) === 1 && ($indexName === $autoName || $indexName === $autoUniqueName)) {
            $indexName = $indexColumns[0];
        }

        $parts = [];
        foreach ($indexColumns as $colName) {
            $idx = collect($columns)->search(fn ($c) => $c->get('name') === $colName);
            if ($idx === false) continue;
            $col = $columns[$idx];
            $parts[] = '{' . ($idx + 1) . ", '{$this->typeFor($col)}'}";
        }

        $unique = !empty($command->unique) ? 'true' : 'false';

        return "box.space['{$space}']:create_index('{$indexName}', {type='tree', unique={$unique}, parts={" . implode(',', $parts) . "}, if_not_exists=true})";
    }

    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        $command->unique = true;
        return $this->compileIndex($blueprint, $command);
    }

    public function compileCreate(Blueprint $blueprint, Fluent $command): array
    {
        $space = $this->spaceName($blueprint);
        $columns = $blueprint->getColumns();

        $format = [];
        foreach ($columns as $col) {
            $nullable = $col->get('nullable') ? 'true' : 'false';
            if ($col->get('primary') || $col->get('autoIncrement') || $col->get('auto_increment')) {
                $nullable = 'false';
            }
            $format[] = "{name='{$col->get('name')}', type='{$this->typeFor($col)}', is_nullable={$nullable}}";
        }

        $lua = [
            "box.schema.space.create('{$space}', {if_not_exists=true})",
            "box.space['{$space}']:format({" . implode(',', $format) . "})",
        ];

        // id() sets autoIncrement, not a primary command, so treat it as primary
        $primaryCols = collect($columns)
            ->filter(fn ($c) => $c->get('primary') || $c->get('autoIncrement') || $c->get('auto_increment') || ($c->get('name') === 'id' && str_contains($this->typeFor($c), 'unsigned')))
            ->values();

        if ($primaryCols->isNotEmpty()) {
            $parts = [];
            foreach ($primaryCols as $col) {
                $idx = collect($columns)->search(fn ($c) => $c->get('name') === $col->get('name'));
                $parts[] = '{' . ($idx + 1) . ", '{$this->typeFor($col)}'}";
            }

            $hasSequence = $primaryCols->contains(fn ($c) => $c->get('autoIncrement') || $c->get('auto_increment') || $c->get('name') === 'id');

            // drop old primary so a re-run of the same space name actually gets the sequence
            $lua[] = "if box.space['{$space}'].index.primary ~= nil then box.space['{$space}'].index.primary:drop() end";

            if ($hasSequence) {
                $seqName = "{$space}_id_seq";
                $lua[] = "box.schema.sequence.create('{$seqName}', {if_not_exists=true})";
                $lua[] = "box.space['{$space}']:create_index('primary', {type='tree', unique=true, parts={" . implode(',', $parts) . "}, sequence='{$seqName}', if_not_exists=true})";
            } else {
                $lua[] = "box.space['{$space}']:create_index('primary', {type='tree', unique=true, parts={" . implode(',', $parts) . "}, if_not_exists=true})";
            }
        }

        return $lua;
    }

    public function compilePrimary(Blueprint $blueprint, Fluent $command): string
    {
        $space = $this->spaceName($blueprint);
        $columns = $blueprint->getColumns();
        $names = collect($command->get('columns'))->toArray();

        if (empty($names)) {
            $names = collect($columns)->filter(fn ($c) => $c->get('primary'))->pluck('name')->toArray();
        }

        $parts = [];
        foreach ($names as $name) {
            $idx = collect($columns)->search(fn ($c) => $c->get('name') === $name);
            $col = $columns[$idx];
            $parts[] = '{' . ($idx + 1) . ", '{$this->typeFor($col)}'}";
        }

        $hasSequence = collect($columns)
            ->filter(fn ($c) => in_array($c->get('name'), $names, true))
            ->contains(fn ($c) => $c->get('autoIncrement') || $c->get('auto_increment'));

        $seq = $hasSequence ? ', sequence=true' : '';

        return "box.space['{$space}']:create_index('primary', {type='tree', unique=true, parts={" . implode(',', $parts) . "}{$seq}, if_not_exists=true})";
    }

    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        $space = $this->spaceName($blueprint);
        return "local s = box.space['{$space}']; if s ~= nil then s:drop() end";
    }

    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDrop($blueprint, $command);
    }

    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        $space = $this->spaceName($blueprint);
        $columns = $blueprint->getColumns();

        $adds = [];
        foreach ($columns as $col) {
            $nullable = $col->get('nullable') ? 'true' : 'false';
            $adds[] = "{name='{$col->get('name')}', type='{$this->typeFor($col)}', is_nullable={$nullable}}";
        }

        return "local f = box.space['{$space}']:format(); for _, c in ipairs({" . implode(',', $adds) . "}) do table.insert(f, c) end; box.space['{$space}']:format(f)";
    }

    
}