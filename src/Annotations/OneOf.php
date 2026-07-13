<?php declare(strict_types=1);

namespace OpenApi\Annotations;

use OpenApi\Undefined;

/**
 * Source-only typed response-body shorthand that expands to Schema::oneOf.
 *
 * @Annotation
 */
class OneOf extends AbstractAnnotation
{
    /** @var list<string|class-string|object> */
    public $types = Undefined::UNDEFINED;

    public static $_types = ['types' => '[string]'];

    public static $_blacklist = ['_context', '_unmerged', '_analysis', 'attachables', 'types'];
}
