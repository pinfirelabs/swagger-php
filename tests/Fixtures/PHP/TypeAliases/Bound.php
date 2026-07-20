<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Fixtures\PHP\TypeAliases;

use OpenApi\Attributes as OAT;

/**
 * @phpstan-type InvoiceLine array{item: OrderItem, price: numeric-string}
 */
#[OAT\Schema(
    schema: 'InvoiceLineResponse',
    typeAlias: 'InvoiceLine',
    description: 'A serialized invoice line',
    properties: [
        new OAT\Property(property: 'price', description: 'Decimal string amount', example: '9.95'),
    ],
)]
class Bound
{
}
