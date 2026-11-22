<?php

namespace Tarantool\Nosql;

/** @internal - return type only, not for direct construction */
final class ReadOnlyRecord extends \ArrayObject
{
    public function __construct(array $input = [])
    {
        parent::__construct($input, \ArrayObject::ARRAY_AS_PROPS);
    }

    public static function fromArray(array $input): self
    {
        return new self($input);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        throw new \RuntimeException(
            "Tarantool strict standard enforced: Returned records are read-only. " .
            "Cannot mutate '{$key}' directly; use Model::where(['id' => \$id])->update([...])."
        );
    }

    public function offsetUnset(mixed $key): void
    {
        throw new \RuntimeException(
            "Tarantool strict standard enforced: Returned records are read-only. Cannot unset '{$key}'."
        );
    }
}