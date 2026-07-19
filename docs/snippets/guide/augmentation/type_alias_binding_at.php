<?php

namespace Openapi\Snippets\Augmentation\TypeAliasBinding;

use OpenApi\Attributes as OA;

#[OA\Schema]
class FinanceItem
{
    #[OA\Property]
    public string $sku;
}

/**
 * @phpstan-type InvoiceLine array{item: FinanceItem, price: numeric-string}
 */
#[OA\Schema(
    schema: 'InvoiceLineResponse',
    typeAlias: 'InvoiceLine',
    description: 'A serialized invoice line',
)]
class ApiTypes
{
}
