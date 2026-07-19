<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Fixtures\PHP\TypeAliases;

use OpenApi\Attributes as OAT;

/**
 * @property IterableItem[]              $list
 * @property IterableItem                $one
 * @property IterableItem|null           $maybe
 * @property array<string, IterableItem> $generic
 */
#[OAT\Schema(schema: 'IterableMagic', pick: ['list', 'one', 'maybe', 'generic'])]
class IterableMagic
{
}

/**
 * Active-record style container: TypeInfo coerces Traversable/ArrayAccess
 * implementors into collections unless the class itself is the schema subject.
 *
 * @implements \IteratorAggregate<int, mixed>
 * @implements \ArrayAccess<string, mixed>
 */
#[OAT\Schema(schema: 'IterableItem', properties: [
    new OAT\Property(property: 'id', type: 'integer'),
])]
class IterableItem implements \IteratorAggregate, \ArrayAccess
{
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator([]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}
