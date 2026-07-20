<?php declare(strict_types=1);

/**
 * @license Apache-2.0
 */

namespace OpenApi\Tests\Fixtures;

use OpenApi\Attributes as OAT;

/**
 * @property int         $id   Internal user identifier
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
#[OAT\Schema(
    schema: 'VirtualUserFormattedName',
    pick: ['name'],
    properties: [new OAT\Property(property: 'name', format: 'email')],
)]
class ExpandedSchemaProperties
{
}

/**
 * @property int         $id   Account id
 * @property string|null $name Display name
 * @property-write string $secret Setter-only secret
 */
#[OAT\Schema(schema: 'WritableAccount', pick: ['id', 'secret'])]
#[OAT\Schema(
    schema: 'WritableAccountRenamed',
    pick: ['id', 'name'],
    required: ['id', 'name'],
    rename: ['name' => 'displayName'],
)]
#[OAT\Schema(schema: 'PlainWritableAccount')]
class WritableAccount
{
}

/**
 * @OA\Schema(
 *     schema="DocblockWritableAccount",
 *     pick={"id", "secret"}
 * )
 * @OA\Schema(
 *     schema="DocblockWritableAccountRenamed",
 *     pick={"id", "name"},
 *     required={"id", "name"},
 *     rename={"name": "displayName"}
 * )
 *
 * @property int         $id   Account id
 * @property string|null $name Display name
 * @property-write string $secret Setter-only secret
 */
class DocblockWritableAccount
{
}

/**
 * @property int    $code  Numeric code
 * @property string $label Label text
 */
#[OAT\Schema(schema: 'PinBase', pick: ['code'])]
#[OAT\Schema(schema: 'PinComposed', base: 'PinBase', pick: ['label'])]
#[OAT\Schema(schema: 'PinFlattened', base: 'PinComposed', omit: ['code'])]
class PinnedSchemaProjection
{
}

#[OAT\Schema(
    schema: 'BaseWithExplicitFlag',
    properties: [
        new OAT\Property(property: 'flag', type: 'boolean'),
        new OAT\Property(property: 'label', type: 'string'),
    ],
)]
#[OAT\Schema(
    schema: 'DerivedOmitsExplicitFlag',
    base: 'BaseWithExplicitFlag',
    omit: ['flag'],
)]
class ExplicitPropertyOmission
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
 * @property int         $id   Internal user identifier
 * @property string|null $name Display name
 * @property-read bool $active Whether the user is active
 * @property-read DocblockExpandedSchemaProperties[] $children Child users
 */
class DocblockExpandedSchemaProperties
{
}

/**
 * @property string $message
 */
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

/**
 * @property int    $id
 * @property string $label
 */
#[OAT\Schema(schema: 'PhantomRequired', pick: ['id', 'label'], required: ['id', 'phantom_column'])]
class PhantomRequired
{
}

/**
 * @property-read int    $id        Primary key
 * @property string $createdAt Creation timestamp
 */
class InheritedRecordBase
{
}

/**
 * @property string $name Equipment name
 */
#[OAT\Schema(schema: 'InheritedEquipment', pick: ['id', 'createdAt', 'name'])]
class InheritedEquipment extends InheritedRecordBase
{
}

/**
 * @property-read int $value Base numeric value
 */
class OverrideBase
{
}

/**
 * @property string $value Overridden string value
 */
#[OAT\Schema(schema: 'OverrideChild', pick: ['value'])]
class OverrideChild extends OverrideBase
{
}

/**
 * @property-read int $grandId Grandparent id
 */
class GrandParentRecord
{
}

/**
 * @property string $parentField Parent field
 */
class ParentRecord extends GrandParentRecord
{
}

/**
 * @property bool $childFlag Child flag
 */
#[OAT\Schema(schema: 'MultiLevelChild', pick: ['grandId', 'parentField', 'childFlag'])]
class MultiLevelChild extends ParentRecord
{
}

/**
 * This is the model class for table "nested_explicit".
 *
 * The followings are the available columns in table 'nested_explicit':
 * @property int $id Row id
 */
#[OAT\Schema(
    schema: 'NestedExplicitNoDescription',
    pick: ['id'],
    properties: [
        new OAT\Property(property: 'kind', type: 'string'),
    ],
)]
class NestedExplicitNoDescription
{
}
