<?php

namespace Openapi\Snippets\Augmentation\SchemaProjectionComponents;

use OpenApi\Attributes as OA;

#[OA\Components]
#[OA\Schema(
    schema: 'Problem',
    type: 'object',
    properties: [
        new OA\Property(
            property: 'title',
            type: 'string',
        ),
    ],
)]
#[OA\Schema(
    schema: 'ValidationProblem',
    base: 'Problem',
    properties: [
        new OA\Property(
            property: 'title',
            enum: ['validation_failed'],
        ),
    ],
)]
class ApiProblems
{
}
