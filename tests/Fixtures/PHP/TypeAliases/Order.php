<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Fixtures\PHP\TypeAliases;

use OpenApi\Attributes as OAT;

/**
 * @phpstan-type OrderLine array{item: OrderItem, price: numeric-string, quantity: int, eventUuid?: string}
 * @phpstan-type Uuid string
 * @phpstan-type LineList OrderLine[]
 */
#[OAT\Schema]
class Order
{
    /** @var OrderLine */
    #[OAT\Property]
    public array $line;

    /** @var OrderLine|null */
    #[OAT\Property]
    public ?array $optionalLine;

    /** @var OrderLine[] */
    #[OAT\Property]
    public array $lines;

    /** @var array<string, OrderLine> */
    #[OAT\Property]
    public array $linesByKey;

    /** @var Uuid */
    #[OAT\Property]
    public string $uuid;

    /** @var LineList */
    #[OAT\Property]
    public array $lineList;
}
