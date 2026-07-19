<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Fixtures\PHP\TypeAliases;

use OpenApi\Attributes as OAT;

/**
 * @phpstan-type ItemList OrderItem[]
 *
 * @property OrderItem[] $list
 * @property OrderItem $one
 * @property OrderItem|null $maybe
 * @property ItemList $aliased
 */
#[OAT\Schema(
    schema: 'Magic',
    pick: [
        'list',
        'one',
        'maybe',
        'aliased',
    ],
)]
class MagicProps
{
}
