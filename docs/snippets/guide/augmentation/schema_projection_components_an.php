<?php

namespace Openapi\Snippets\Augmentation\SchemaProjectionComponents;

use OpenApi\Annotations as OA;

/**
 * @OA\Components
 * @OA\Schema(
 *     schema="Problem",
 *     type="object",
 *     @OA\Property(
 *         property="title",
 *         type="string"
 *     )
 * )
 * @OA\Schema(
 *     schema="ValidationProblem",
 *     base="Problem",
 *     @OA\Property(
 *         property="title",
 *         enum={"validation_failed"}
 *     )
 * )
 */
class ApiProblems
{
}
