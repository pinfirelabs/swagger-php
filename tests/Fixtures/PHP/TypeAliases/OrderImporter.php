<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Fixtures\PHP\TypeAliases;

use OpenApi\Attributes as OAT;

/**
 * @phpstan-import-type OrderLine from Order as ImportedLine
 */
#[OAT\Schema]
class OrderImporter
{
    /** @var ImportedLine[] */
    #[OAT\Property]
    public array $importedLines;
}
