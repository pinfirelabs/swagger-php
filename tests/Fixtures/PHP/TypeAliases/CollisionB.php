<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Fixtures\PHP\TypeAliases;

use OpenApi\Attributes as OAT;

/**
 * @phpstan-type Status array{label: string}
 */
#[OAT\Schema]
class CollisionB
{
    /** @var Status */
    #[OAT\Property]
    public array $status;
}
