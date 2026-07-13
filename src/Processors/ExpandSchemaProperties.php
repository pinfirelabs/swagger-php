<?php declare(strict_types=1);

/**
 * @license Apache-2.0
 */

namespace OpenApi\Processors;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\GeneratorAwareInterface;
use OpenApi\GeneratorAwareTrait;
use OpenApi\Type\TypeInfoTypeResolver;
use OpenApi\Type\TypeResolver;
use OpenApi\Undefined;

/**
 * Expand source-level Schema base/pick/omit/rename metadata into ordinary
 * OpenAPI properties and composition annotations.
 */
class ExpandSchemaProperties implements GeneratorAwareInterface
{
    use GeneratorAwareTrait;

    private TypeResolver $typeResolver;

    /** @var array<int,array{properties: array<string,OA\Property>,required: list<string>}> */
    private array $expanded = [];

    /** @var array<int,bool> */
    private array $expanding = [];

    public function __construct()
    {
        $this->typeResolver = new TypeResolver();
    }

    public function __invoke(Analysis $analysis): void
    {
        $this->expanded = [];
        $this->expanding = [];

        foreach ($analysis->getAnnotationsOfType(OA\Schema::class) as $schema) {
            if (!$schema->isRoot(OA\Schema::class) || $schema->_context->is('nested')) {
                continue;
            }
            if (Undefined::isDefault($schema->pick, $schema->omit, $schema->base, $schema->rename)) {
                $this->expandTypeAlias($analysis, $schema);
                continue;
            }
            $this->expandTypeAlias($analysis, $schema);
            $this->expand($analysis, $schema);
        }
    }

    private function expandTypeAlias(Analysis $analysis, OA\Schema $schema): void
    {
        if (Undefined::isDefault($schema->typeAlias) || !$this->generator->getTypeResolver() instanceof TypeInfoTypeResolver) {
            return;
        }
        $context = $schema->_context->with('class') ?: $schema->_context->with('interface');
        if (!$context || !$context->reflector instanceof \Reflector) {
            $schema->_context->logger->warning('Schema ' . $schema->identity() . ' has typeAlias but no class or interface source context');

            return;
        }
        $this->generator->getTypeResolver()->augmentSchemaTypeFromString(
            $analysis,
            $schema,
            $schema->typeAlias,
            $context->reflector,
            OA\Schema::class,
            Undefined::isDefault($schema->refs) ? [] : $schema->refs,
        );
    }

    /**
     * @return array{properties: array<string,OA\Property>,required: list<string>}
     */
    private function expand(Analysis $analysis, OA\Schema $schema): array
    {
        $id = spl_object_id($schema);
        if (isset($this->expanded[$id])) {
            return $this->expanded[$id];
        }
        if (isset($this->expanding[$id])) {
            $schema->_context->logger->error('Schema projection base cycle detected for ' . $schema->identity() . ' in ' . $schema->_context);

            return ['properties' => [], 'required' => []];
        }
        $this->expanding[$id] = true;

        $local = $this->localProperties($analysis, $schema);
        $base = $this->findBase($analysis, $schema);
        $baseExpanded = $base ? $this->expand($analysis, $base) : ['properties' => [], 'required' => []];
        $omit = Undefined::isDefault($schema->omit) ? [] : $schema->omit;

        $properties = $baseExpanded['properties'];
        foreach ($local['properties'] as $name => $property) {
            $properties[$name] = $property;
        }
        foreach ($omit as $name) {
            unset($properties[$name]);
        }

        $required = array_values(array_unique(array_merge($baseExpanded['required'], $local['required'])));
        $required = array_values(array_filter($required, static fn (string $name): bool => isset($properties[$name])));

        if ($base instanceof OA\Schema && $omit === []) {
            $this->compose($analysis, $schema, $base, $local);
        } elseif ($base instanceof OA\Schema) {
            $schema->properties = array_values($properties);
            $schema->required = $required === [] ? Undefined::UNDEFINED : $required;
        } else {
            $schema->properties = array_values($local['properties']);
            $schema->required = $local['required'] === [] ? Undefined::UNDEFINED : $local['required'];
        }

        $this->expanded[$id] = ['properties' => $properties, 'required' => $required];
        unset($this->expanding[$id]);

        return $this->expanded[$id];
    }

    /**
     * @return array{properties: array<string,OA\Property>,required: list<string>}
     */
    private function localProperties(Analysis $analysis, OA\Schema $schema): array
    {
        $pool = $this->propertyPool($schema);
        $pick = Undefined::isDefault($schema->pick) ? array_keys($pool) : $schema->pick;
        $omit = Undefined::isDefault($schema->omit) ? [] : $schema->omit;
        $rename = Undefined::isDefault($schema->rename) ? [] : $schema->rename;
        $properties = [];

        foreach ($pick as $sourceName) {
            if (in_array($sourceName, $omit, true)) {
                continue;
            }
            if (!isset($pool[$sourceName])) {
                $schema->_context->logger->warning('Schema ' . $schema->identity() . ' picks unknown property "' . $sourceName . '" in ' . $schema->_context);
                continue;
            }
            $property = $pool[$sourceName];
            $outputName = $rename[$sourceName] ?? $sourceName;
            $property->property = $outputName;
            $properties[$outputName] = $property;
            $analysis->addAnnotation($property, $property->_context);
        }

        // Existing explicit properties are always the final override. When an
        // override targets a property inferred from PHP reflection or PHPDoc,
        // merge it into that inferred property so its type and description
        // remain the single source of truth.
        foreach ((array) $schema->properties as $explicitProperty) {
            if (!$explicitProperty instanceof OA\Property) {
                continue;
            }
            $name = Undefined::isDefault($explicitProperty->property) ? $explicitProperty->_context->property : $explicitProperty->property;
            if (!is_string($name) || in_array($name, $omit, true)) {
                continue;
            }
            $property = $explicitProperty;
            if (isset($pool[$name])) {
                $property = $pool[$name];
                foreach (get_object_vars($explicitProperty) as $propertyName => $value) {
                    if (str_starts_with($propertyName, '_') || Undefined::isDefault($value)) {
                        continue;
                    }
                    $property->{$propertyName} = $value;
                }
                $analysis->removeAnnotation($explicitProperty);
            }
            $property->property = $name;
            $properties[$name] = $property;
            $analysis->addAnnotation($property, $property->_context);
        }

        $required = [];
        foreach ((array) $schema->required as $name) {
            $outputName = $rename[$name] ?? $name;
            if (isset($properties[$outputName])) {
                $required[] = $outputName;
            }
        }

        return ['properties' => $properties, 'required' => array_values(array_unique($required))];
    }

    /**
     * @return array<string,OA\Property>
     */
    private function propertyPool(OA\Schema $schema): array
    {
        $classContext = $schema->_context->with('class')
            ?: $schema->_context->with('interface')
            ?: $schema->_context->with('trait');
        if (!$classContext || !$classContext->reflector instanceof \ReflectionClass) {
            return [];
        }

        $pool = [];
        foreach ($classContext->reflector->getProperties() as $reflectionProperty) {
            if ($reflectionProperty->getDeclaringClass()->getName() !== $classContext->reflector->getName()) {
                continue;
            }
            $context = new Context([
                'property' => $reflectionProperty->getName(),
                'comment' => $reflectionProperty->getDocComment() ?: null,
                'reflector' => $reflectionProperty,
                'schemaRefs' => Undefined::isDefault($schema->refs) ? [] : $schema->refs,
                'generated' => true,
            ], $schema->_context);
            $pool[$reflectionProperty->getName()] = new OA\Property([
                'property' => $reflectionProperty->getName(),
                '_context' => $context,
            ]);
        }

        foreach ($this->typeResolver->getDocblockProperties($classContext->reflector) as $virtualProperty) {
            $context = new Context([
                'property' => $virtualProperty['name'],
                'virtualType' => $virtualProperty['type'],
                'comment' => $virtualProperty['description'],
                'virtualDescription' => $virtualProperty['description'],
                'virtualReadOnly' => $virtualProperty['readOnly'],
                'virtualWriteOnly' => $virtualProperty['writeOnly'],
                'reflector' => $classContext->reflector,
                'schemaRefs' => Undefined::isDefault($schema->refs) ? [] : $schema->refs,
                'generated' => true,
            ], $schema->_context);
            $properties = [
                'property' => $virtualProperty['name'],
                '_context' => $context,
            ];
            if ($virtualProperty['description'] !== '') {
                $properties['description'] = $virtualProperty['description'];
            }
            if ($virtualProperty['readOnly']) {
                $properties['readOnly'] = true;
            }
            if ($virtualProperty['writeOnly']) {
                $properties['writeOnly'] = true;
            }
            $pool[$virtualProperty['name']] = new OA\Property($properties);
        }

        return $pool;
    }

    private function findBase(Analysis $analysis, OA\Schema $schema): ?OA\Schema
    {
        if (Undefined::isDefault($schema->base)) {
            return null;
        }
        $base = is_object($schema->base) ? get_class($schema->base) : (string) $schema->base;
        foreach ($analysis->getAnnotationsOfType(OA\Schema::class) as $candidate) {
            if ($candidate !== $schema && $candidate->isRoot(OA\Schema::class) && $candidate->schema === $base) {
                return $candidate;
            }
        }
        $schema->_context->logger->error('Schema ' . $schema->identity() . ' references unknown base "' . $base . '" in ' . $schema->_context);

        return null;
    }

    /**
     * @param array{properties: array<string,OA\Property>,required: list<string>} $local
     */
    private function compose(Analysis $analysis, OA\Schema $schema, OA\Schema $base, array $local): void
    {
        $context = new Context(['generated' => true], $schema->_context);
        $ref = new OA\Schema([
            'ref' => OA\Components::ref($base),
            '_context' => $context,
        ]);
        $payload = new OA\Schema([
            'type' => 'object',
            'properties' => array_values($local['properties']),
            'required' => $local['required'] === [] ? Undefined::UNDEFINED : $local['required'],
            '_context' => $context,
        ]);
        $schema->properties = Undefined::UNDEFINED;
        $schema->required = Undefined::UNDEFINED;
        $schema->allOf = array_merge(Undefined::isDefault($schema->allOf) ? [] : $schema->allOf, [$ref, $payload]);
        $analysis->addAnnotation($ref, $ref->_context);
        $analysis->addAnnotation($payload, $payload->_context);
    }
}
