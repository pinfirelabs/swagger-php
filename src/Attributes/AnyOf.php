<?php declare(strict_types=1);

namespace OpenApi\Attributes;

use OpenApi\Annotations as OA;

#[\Attribute(\Attribute::TARGET_ALL)]
class AnyOf extends OA\AnyOf
{
    public function __construct(string|object ...$types)
    {
        parent::__construct(['types' => $types]);
    }
}
