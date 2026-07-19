<?php

namespace Openapi\Snippets\Augmentation\TypeAliasPromotion;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema
 */
class FinanceItem
{
    /**
     * @OA\Property
     */
    public string $sku;
}

/**
 * @phpstan-type InvoiceLine array{
 *     item: FinanceItem,
 *     price: numeric-string,
 *     quantity: positive-int,
 *     note?: string|null
 * }
 *
 * @OA\Schema
 */
class Invoice
{
    /**
     * @var list<InvoiceLine>
     * @OA\Property
     */
    public array $lines;
}
