<?php

declare(strict_types=1);

namespace MilesChou\Monoex\Teams;

use ArrayAccess;
use JsonSerializable;
use ReturnTypeWillChange;

class LoggerMessage implements ArrayAccess, JsonSerializable
{
    public function __construct(private array $data = [])
    {
    }

    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    public function jsonSerialize(): mixed
    {
        return array_merge([
            '@context' => 'http://schema.org/extensions',
            '@type'    => 'MessageCard',
        ], $this->data);
    }
}
