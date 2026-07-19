<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Fixtures\PHP\TypeAliases;

use OpenApi\Attributes as OAT;

/**
 * @property OrderItem[]    $list
 * @property OrderItem      $one
 * @property OrderItem|null $maybe
 */
#[OAT\Schema(schema: 'NoAliasMagic', pick: ['list', 'one', 'maybe'])]
class NoAliasMagic
{
}
