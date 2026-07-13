<?php declare(strict_types=1);

namespace OpenApi\Attributes;

use OpenApi\Annotations as OA;
use OpenApi\Undefined;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class OperationDefaults extends OA\OperationDefaults
{
    /**
     * @param list<string>|null $tags
     * @param list<Parameter>|null $parameters
     * @param array|null $security
     */
    public function __construct(
        ?array $tags = null,
        ?array $parameters = null,
        ?array $security = null,
        ?string $operationIdPrefix = null,
    ) {
        parent::__construct([
            'tags' => $tags ?? Undefined::UNDEFINED,
            'parameters' => $parameters ?? Undefined::UNDEFINED,
            'security' => $security ?? Undefined::UNDEFINED,
            'operationIdPrefix' => $operationIdPrefix ?? Undefined::UNDEFINED,
        ]);
    }
}
