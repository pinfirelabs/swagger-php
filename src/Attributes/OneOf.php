<?php declare(strict_types=1);

namespace OpenApi\Attributes;

use OpenApi\Annotations as OA;

#[\Attribute(\Attribute::TARGET_ALL)]
class OneOf extends OA\OneOf
{
    public function __construct(string|object ...$types)
    {
        parent::__construct(['types' => $types]);
    }
}
