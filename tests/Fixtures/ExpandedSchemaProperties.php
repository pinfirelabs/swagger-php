<?php declare(strict_types=1);

/**
 * @license Apache-2.0
 */

namespace OpenApi\Tests\Fixtures;

use OpenApi\Attributes as OAT;

/**
 * @property int $id Internal user identifier
 * @property string|null $name Display name
 * @property-read bool $active Whether the user is active
 * @property-read ExpandedSchemaProperties[] $children Child users
 */
#[OAT\Info(title: 'Expanded schema fixture', version: 'test')]
#[OAT\Schema(
    schema: 'VirtualUser',
    canonical: true,
    required: ['id'],
    pick: ['id', 'name', 'active'],
)]
#[OAT\Schema(
    schema: 'VirtualUserWithChildren',
    base: 'VirtualUser',
    pick: ['children'],
)]
#[OAT\Schema(
    schema: 'VirtualUserWithoutName',
    base: 'VirtualUserWithChildren',
    omit: ['name'],
)]
class ExpandedSchemaProperties
{
}

/**
 * @OA\Schema(
 *     schema="DocblockVirtualUser",
 *     canonical=true,
 *     required={"id"},
 *     pick={"id", "name", "active"}
 * )
 * @OA\Schema(
 *     schema="DocblockVirtualUserWithChildren",
 *     base="DocblockVirtualUser",
 *     pick={"children"}
 * )
 * @OA\Schema(
 *     schema="DocblockVirtualUserWithoutName",
 *     base="DocblockVirtualUserWithChildren",
 *     omit={"name"}
 * )
 *
 * @property int $id Internal user identifier
 * @property string|null $name Display name
 * @property-read bool $active Whether the user is active
 * @property-read DocblockExpandedSchemaProperties[] $children Child users
 */
class DocblockExpandedSchemaProperties
{
}

/** @property string $message */
#[OAT\Schema(schema: 'ExpandedProblem', canonical: true, pick: ['message'])]
class ExpandedProblem
{
}

class ResponseShorthandFixture
{
    #[OAT\Post(
        path: '/expanded',
        body: ExpandedSchemaProperties::class,
        responses: [
            201 => ExpandedSchemaProperties::class,
            400 => new OAT\OneOf(ExpandedSchemaProperties::class, ExpandedProblem::class),
            403 => null,
        ],
    )]
    public function create(): void
    {
    }
}
