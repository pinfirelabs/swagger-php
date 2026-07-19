<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Fixtures\PHP\TypeAliases;

use OpenApi\Attributes as OAT;

#[OAT\Schema]
class OrderItem
{
    #[OAT\Property]
    public int $id;

    #[OAT\Property]
    public string $name;
}
