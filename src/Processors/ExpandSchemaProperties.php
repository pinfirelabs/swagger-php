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
use OpenApi\Type\TypeAliases;
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

    private TypeAliases $typeAliases;

    /** @var array<int,array{properties: array<string,OA\Property>,required: list<string>}> */
    private array $expanded = [];

    /** @var array<int,bool> */
    private array $expanding = [];

    public function __construct()
    {
        $this->typeResolver = new TypeResolver();
        $this->typeAliases = new TypeAliases();
    }

    public function __invoke(Analysis $analysis): void
    {
        $this->expanded = [];
        $this->expanding = [];

        foreach ($analysis->getAnnotationsOfType(OA\Schema::class) as $schema) {
            if (!Analysis::isComponentSchema($schema)) {
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

        // When the typeAlias names a declared @phpstan-type/@psalm-type alias on the
        // source class, expand its definition inline so the bound schema carries the
        // alias body rather than referencing itself.
        $typeString = $schema->typeAlias;
        if ($context->reflector instanceof \ReflectionClass) {
            $aliases = $this->typeAliases->forClass($context->reflector);
            if (isset($aliases[$typeString]) && $aliases[$typeString]['type'] !== null) {
                $typeString = $aliases[$typeString]['type'];
            }
        }

        // Explicit properties on a bound schema decorate single keys of the expanded
        // shape. Stash them (and the object type AugmentSchemas stamped for them) so
        // the shape still expands, then fold each override onto its expanded property.
        $overrides = [];
        if (!Undefined::isDefault($schema->properties)) {
            foreach ((array) $schema->properties as $property) {
                if (!$property instanceof OA\Property) {
                    continue;
                }
                $name = Undefined::isDefault($property->property) ? $property->_context->property : $property->property;
                if (is_string($name)) {
                    $overrides[$name] = $property;
                }
            }
            $schema->properties = Undefined::UNDEFINED;
            if ($schema->type === 'object') {
                $schema->type = Undefined::UNDEFINED;
            }
        }

        $this->generator->getTypeResolver()->augmentSchemaTypeFromString(
            $analysis,
            $schema,
            $typeString,
            $context->reflector,
            OA\Schema::class,
            Undefined::isDefault($schema->refs) ? [] : $schema->refs,
        );

        if ([] === $overrides) {
            return;
        }

        $properties = [];
        foreach (Undefined::isDefault($schema->properties) ? [] : (array) $schema->properties as $property) {
            if ($property instanceof OA\Property && is_string($property->property)) {
                $properties[$property->property] = $property;
            }
        }

        foreach ($overrides as $name => $override) {
            $expanded = $properties[$name] ?? null;
            if ($expanded instanceof OA\Property) {
                foreach (get_object_vars($override) as $field => $value) {
                    if (str_starts_with($field, '_') || Undefined::isDefault($value)) {
                        continue;
                    }
                    $expanded->{$field} = $value;
                }
                $analysis->removeAnnotation($override);
            } else {
                $override->property = $name;
                $properties[$name] = $override;
                $analysis->addAnnotation($override, $override->_context);
            }
        }

        $schema->properties = array_values($properties);
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
        $required = array_values(array_filter($required, static fn (string $name): bool => !in_array($name, $omit, true)));

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

        // explicit required entries pass through even without a matching property
        // (JSON Schema allows requiring keys the schema does not describe); only
        // omitted names drop out
        $required = [];
        foreach (Undefined::isDefault($schema->required) ? [] : (array) $schema->required as $name) {
            $outputName = $rename[$name] ?? $name;
            if (!in_array($name, $omit, true) && !in_array($outputName, $omit, true)) {
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
            if ($candidate !== $schema && $candidate->schema === $base && Analysis::isComponentSchema($candidate)) {
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
        $context = new Context(['generated' => true, 'comment' => null], $schema->_context);
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
        // the type AugmentSchemas stamped for the (now moved) explicit properties would
        // otherwise linger as a stray top-level `type` next to the allOf
        if ($schema->type === 'object') {
            $schema->type = Undefined::UNDEFINED;
        }
        $schema->allOf = array_merge(Undefined::isDefault($schema->allOf) ? [] : $schema->allOf, [$ref, $payload]);
        $analysis->addAnnotation($ref, $ref->_context);
        $analysis->addAnnotation($payload, $payload->_context);
    }
}
