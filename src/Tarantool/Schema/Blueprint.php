<?php

namespace Tarantool\Nosql\Schema;

class Blueprint
{
    protected string $spaceName;
    protected array $columns = [];
    protected array $indexes = [];
    protected string $primaryKey = 'id';
    protected string $engine = 'memtx';
    protected ?int $lastColumn = null;
    protected bool $primaryKeyDefined = false;
    protected bool $autoIncrement = false;

    public function __construct(string $spaceName)
    {
        $this->spaceName = $spaceName;
    }
    public function autoIncrement(): self
    {
        $this->autoIncrement = true;
        return $this;
    }
    // --- Primary & IDs ---
    public function id(string $column = 'id'): self
    {
        if ($this->primaryKeyDefined) {
            throw new \LogicException("A primary key has already been defined for this space.");
        }
        $this->primaryKey = $column;
        $this->primaryKeyDefined = true;
        $this->autoIncrement = true;
        
        $this->columns[] = [
            'name' => $column,
            'type' => 'unsigned',
            'is_nullable' => false,
        ];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function uuid(string $column = 'id'): self
    {
        if ($this->primaryKeyDefined) {
            throw new \LogicException("A primary key has already been defined for this space.");
        }
        $this->primaryKey = $column;
        $this->primaryKeyDefined = true;

        // Tarantool natively supports uuid format type since v2.4+
        $this->columns[] = [
            'name' => $column,
            'type' => 'uuid',
            'is_nullable' => false,
        ];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    // --- Integers & Numerics ---
    public function tinyInteger(string $column): self { return $this->integer($column); }
    public function smallInteger(string $column): self { return $this->integer($column); }
    public function mediumInteger(string $column): self { return $this->integer($column); }
    
    public function integer(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'integer', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function unsignedInteger(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'unsigned', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function bigInteger(string $column): self { return $this->integer($column); }
    public function unsignedBigInteger(string $column): self { return $this->unsignedInteger($column); }

    public function decimal(string $column, int $total = 8, int $places = 2): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'decimal', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function float(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'double', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function double(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'double', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    // --- Strings, Text & JSON ---
    public function string(string $column, int $length = 255): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'string', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function char(string $column, int $length = 255): self { return $this->string($column, $length); }
    public function text(string $column): self { return $this->string($column); }
    public function mediumText(string $column): self { return $this->string($column); }
    public function longText(string $column): self { return $this->string($column); }

    public function json(string $column): self
    {
        // JSON objects map natively to Tarantool's 'map' format type
        $this->columns[] = ['name' => $column, 'type' => 'map', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    // --- Native Tarantool Document / Complex Types ---
    public function map(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'map', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function array(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'array', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function scalar(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'scalar', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    // --- Booleans & Binary ---
    public function boolean(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'boolean', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function binary(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'varbinary', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    // --- Dates & Timestamps ---
    public function timestamp(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'datetime', 'is_nullable' => true];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function timestamps(): void
    {
        $this->timestamp('created_at');
        $this->timestamp('updated_at');
    }

    public function enum(string $column, array $allowed): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'string', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    public function softDeletes(string $column = 'deleted_at'): self
    {
        $this->timestamp($column)->nullable();
        return $this;
    }

    // --- Native Tarantool Specific Types ---
    public function tarantoolNumber(string $column): self
    {
        $this->columns[] = ['name' => $column, 'type' => 'number', 'is_nullable' => false];
        $this->lastColumn = array_key_last($this->columns);
        return $this;
    }

    // --- Modifiers ---
    public function nullable(): self
    {
        if ($this->lastColumn !== null && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn]['is_nullable'] = true;
        }
        return $this;
    }

    public function default($value): self
    {
        if ($this->lastColumn !== null && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn]['default'] = $value;
        }
        return $this;
    }

    public function unique(): self { return $this->index(true); }

    public function index(bool $unique = false): self
    {
        if ($this->lastColumn !== null && isset($this->columns[$this->lastColumn])) {
            $colName = $this->columns[$this->lastColumn]['name'];
            $this->indexes[] = [
                'name' => $colName,
                'field' => $colName,
                'unique' => $unique
            ];
        }
        return $this;
    }

    public function primary(?string $column = null): self
    {
        if ($this->primaryKeyDefined) {
            throw new \LogicException("A primary key has already been defined for this space.");
        }
        
        if ($column !== null) {
            $this->primaryKey = $column;
        } elseif ($this->lastColumn !== null && isset($this->columns[$this->lastColumn])) {
            $this->primaryKey = $this->columns[$this->lastColumn]['name'];
        }
        
        $this->primaryKeyDefined = true;
        return $this;
    }

    // --- Compilation to Lua ---
    public function executeCreation(): void
    {
        $formatEntries = [];
        foreach ($this->columns as $col) {
            $formatEntries[] = sprintf(
                "{name = '%s', type = '%s', is_nullable = %s}",
                $col['name'],
                $col['type'],
                $col['is_nullable'] ? 'true' : 'false'
            );
        }
        $formatLua = implode(', ', $formatEntries);

        // Find primary key index position
        $primaryFieldIndex = 1;
        foreach ($this->columns as $index => $col) {
            if ($col['name'] === $this->primaryKey) {
                $primaryFieldIndex = $index + 1;
                break;
            }
        }
        $primaryFieldType = $this->columns[$primaryFieldIndex - 1]['type'];

        if ($this->autoIncrement && ! in_array($primaryFieldType, ['unsigned', 'integer'])) {
            throw new \LogicException("Auto-increment requires an integer/unsigned primary key.");
        }

        $sequenceOption = $this->autoIncrement ? ",\n                    sequence = true" : "";

        // Build secondary index scripts
        $indexScripts = [];
        foreach ($this->indexes as $idx) {
            $fieldPos = 1;
            foreach ($this->columns as $i => $col) {
                if ($col['name'] === $idx['field']) {
                    $fieldPos = $i + 1;
                    break;
                }
            }
            $fieldType = $this->columns[$fieldPos - 1]['type'];

            $indexScripts[] = sprintf(
                "s:create_index('%s', { type = 'TREE', unique = %s, parts = { { field = %d, type = '%s' } }, if_not_exists = true })",
                $idx['name'],
                $idx['unique'] ? 'true' : 'false',
                $fieldPos,
                $fieldType
            );
        }
        $secondaryIndexesLua = implode("\n                ", $indexScripts);

        $lua = sprintf("
            if not box.space.%s then
                local s = box.schema.space.create('%s', {
                    engine = '%s',
                    if_not_exists = true
                })
                s:create_index('primary', {
                    type = 'TREE',
                    parts = { { field = %d, type = '%s' } },
                    if_not_exists = true%s
                })
                s:format({ %s })
                %s
            end
        ", 
            $this->spaceName, 
            $this->spaceName, 
            $this->engine, 
            $primaryFieldIndex,
            $primaryFieldType,
            $sequenceOption,
            $formatLua, 
            $secondaryIndexesLua
        );

        app('tarantool.connection.tarantool')->evaluate($lua);
    }
}