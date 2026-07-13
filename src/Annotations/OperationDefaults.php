<?php declare(strict_types=1);

namespace OpenApi\Annotations;

use OpenApi\Undefined;

/**
 * Source-only defaults inherited by operations declared on the same class.
 *
 * @Annotation
 */
class OperationDefaults extends AbstractAnnotation
{
    /** @var list<string> */
    public $tags = Undefined::UNDEFINED;

    /** @var list<Parameter> */
    public $parameters = Undefined::UNDEFINED;

    /** @var array */
    public $security = Undefined::UNDEFINED;

    /** @var string */
    public $operationIdPrefix = Undefined::UNDEFINED;

    public static $_types = [
        'tags' => '[string]',
        'operationIdPrefix' => 'string',
    ];

    public static $_blacklist = ['_context', '_unmerged', '_analysis', 'attachables', 'tags', 'parameters', 'security', 'operationIdPrefix'];
}
