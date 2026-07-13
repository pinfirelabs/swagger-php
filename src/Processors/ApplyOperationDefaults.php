<?php declare(strict_types=1);

namespace OpenApi\Processors;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Undefined;

/** Apply class-level source defaults without adding anything to the OpenAPI output. */
class ApplyOperationDefaults
{
    public function __invoke(Analysis $analysis): void
    {
        $defaults = [];
        foreach ($analysis->getAnnotationsOfType(OA\OperationDefaults::class) as $default) {
            $class = $default->_context->with('class');
            if ($class) {
                $defaults[$class->class][] = $default;
            }
        }

        foreach ($analysis->getAnnotationsOfType(OA\Operation::class) as $operation) {
            $class = $operation->_context->with('class');
            foreach ($class ? $defaults[$class->class] ?? [] : [] as $default) {
                $this->apply($operation, $default);
            }
        }

        foreach ($analysis->getAnnotationsOfType(OA\OperationDefaults::class) as $default) {
            $analysis->removeAnnotation($default);
        }
    }

    private function apply(OA\Operation $operation, OA\OperationDefaults $default): void
    {
        foreach (['tags', 'parameters', 'security'] as $field) {
            if (Undefined::isDefault($operation->{$field}) && !Undefined::isDefault($default->{$field})) {
                $operation->{$field} = $default->{$field};
            }
        }
        if (!Undefined::isDefault($default->operationIdPrefix)
            && !Undefined::isDefault($operation->operationId)
            && !str_starts_with($operation->operationId, $default->operationIdPrefix)) {
            $operation->operationId = $default->operationIdPrefix . $operation->operationId;
        }
    }
}
