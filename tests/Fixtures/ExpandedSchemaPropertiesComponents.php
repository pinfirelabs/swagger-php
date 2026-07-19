<?php declare(strict_types=1);

/**
 * @license Apache-2.0
 */

namespace OpenApi\Tests\Fixtures;

use OpenApi\Attributes as OAT;

/**
 * @phpstan-type ProblemDetails array{title: string, code?: int}
 */
#[OAT\Components(responses: [
    new OAT\Response(
        response: 'ProjectionError400',
        description: 'Validation error',
        content: new OAT\JsonContent(ref: '#/components/schemas/ProjectionValidationError'),
    ),
])]
#[OAT\Schema(schema: 'ProjectionError', type: 'object', properties: [
    new OAT\Property(property: 'success', type: 'boolean', default: false),
    new OAT\Property(property: 'error', type: 'string'),
])]
#[OAT\Schema(schema: 'ProjectionValidationError', base: 'ProjectionError', properties: [
    new OAT\Property(property: 'error', type: 'string', enum: ['ValidationError']),
])]
#[OAT\Schema(typeAlias: 'ProblemDetails', description: 'Problem details')]
class ExpandedSchemaPropertiesComponents
{
}
