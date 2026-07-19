<?php declare(strict_types=1);

/**
 * @license Apache-2.0
 */

namespace OpenApi\Tests\Fixtures;

use OpenApi\Attributes as OAT;

/**
 * Kept out of ExpandedSchemaProperties.php: picking an unknown property logs
 * a warning, so any test loading this file must expect that log entry.
 * Bundling it with the unrelated coverage in the main fixture would couple
 * that expectation onto every test using it.
 *
 * @OA\Schema(
 *     schema="DocblockUnknownPickUser",
 *     pick={"id", "doesNotExist"}
 * )
 *
 * @property int $id Internal identifier
 */
class ExpandedSchemaPropertiesDocblock
{
}

/**
 * A base cycle logs an error; isolated for the same reason as above.
 */
#[OAT\Schema(schema: 'CycleA', base: 'CycleB')]
#[OAT\Schema(schema: 'CycleB', base: 'CycleA')]
class ExpandedSchemaPropertiesDocblockCycle
{
}
