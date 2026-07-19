<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Processors;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\GeneratorAwareInterface;
use OpenApi\GeneratorAwareTrait;
use OpenApi\Type\TypeAliases;
use OpenApi\Type\TypeInfoTypeResolver;
use OpenApi\Undefined;

/**
 * Promote <code>@phpstan-type</code>/<code>@psalm-type</code> aliases to named schema components.
 *
 * Runs between <code>AugmentSchemas</code> and <code>ExpandSchemaProperties</code> so that alias
 * usages in <code>@var</code>/<code>@param</code>/<code>@return</code>/<code>@property</code> and in other
 * aliases resolve to <code>$ref</code>s to the promoted component.
 */
class ExpandTypeAliases implements GeneratorAwareInterface
{
    use GeneratorAwareTrait;

    private TypeAliases $typeAliases;

    public function __construct()
    {
        $this->typeAliases = new TypeAliases();
    }

    public function __invoke(Analysis $analysis): void
    {
        $typeResolver = $this->generator->getTypeResolver();
        if (!$typeResolver instanceof TypeInfoTypeResolver || !$analysis->openapi instanceof OA\OpenApi) {
            return;
        }

        $declared = $this->collectDeclaredAliases($analysis);
        if ([] === $declared) {
            return;
        }

        $usedNames = [];
        $bound = $this->bindTypeAliasSchemas($analysis, $declared, $usedNames);

        // Register shells for every unbound alias before building any body so that
        // alias-to-alias and recursive references resolve during body expansion.
        $shells = [];
        foreach ($declared as $marker => $info) {
            if (isset($bound[$marker])) {
                continue;
            }
            $name = $this->uniqueName($analysis, $info['alias'], $info['class'], $usedNames);
            $usedNames[$name] = true;
            $shell = new OA\Schema([
                'schema' => $name,
                '_context' => new Context(['generated' => true, 'comment' => null], $info['context']),
            ]);
            $analysis->typeAliasSchemas[$marker] = $shell;
            $analysis->addAnnotation($shell, $shell->_context);
            $shells[$marker] = $shell;
        }

        foreach ($shells as $marker => $shell) {
            $info = $declared[$marker];
            $typeResolver->augmentSchemaTypeFromString($analysis, $shell, $info['type'], $info['class']);
            if (!$this->hasBody($shell)) {
                unset($analysis->typeAliasSchemas[$marker], $shells[$marker]);
                $analysis->removeAnnotation($shell);
                $shell->_context->logger->warning('Unable to resolve type alias "' . $info['alias'] . '" declared in ' . $info['owner']);
            }
        }

        $this->mergeIntoComponents($analysis, $shells);
    }

    /**
     * @param  array<string, mixed> $usedNames
     * @return array<string, true>  markers that were bound to an explicit schema
     */
    private function bindTypeAliasSchemas(Analysis $analysis, array $declared, array &$usedNames): array
    {
        $bound = [];
        foreach ($analysis->getAnnotationsOfType(OA\Schema::class) as $schema) {
            if (!Analysis::isComponentSchema($schema) || $schema->_context->is('generated') || Undefined::isDefault($schema->typeAlias)) {
                continue;
            }
            $class = $this->schemaClass($schema);
            if (!$class instanceof \ReflectionClass) {
                continue;
            }
            $aliases = $this->typeAliases->forClass($class);
            $local = (string) $schema->typeAlias;
            if (!isset($aliases[$local])) {
                continue;
            }
            $marker = TypeAliases::marker($aliases[$local]['owner'], $aliases[$local]['alias']);
            if (!isset($declared[$marker]) || isset($bound[$marker])) {
                continue;
            }

            $analysis->typeAliasSchemas[$marker] = $schema;
            $bound[$marker] = true;
            if (Undefined::isDefault($schema->schema)) {
                $schema->schema = $this->uniqueName($analysis, $aliases[$local]['alias'], $declared[$marker]['class'], $usedNames);
            }
            $usedNames[$schema->schema] = true;
        }

        return $bound;
    }

    /**
     * @return array<string, array{owner: string, alias: string, type: string, class: \ReflectionClass, context: Context}> keyed by marker
     */
    private function collectDeclaredAliases(Analysis $analysis): array
    {
        $sources = $analysis->classes + $analysis->interfaces + $analysis->traits + $analysis->enums;
        ksort($sources);

        $declared = [];
        foreach ($sources as $fqdn => $definition) {
            try {
                $reflection = new \ReflectionClass(ltrim((string) $fqdn, '\\'));
            } catch (\Throwable) {
                continue;
            }
            $owner = ltrim($reflection->getName(), '\\');
            foreach ($this->typeAliases->forClass($reflection) as $info) {
                if ($info['owner'] !== $owner || $info['templated'] || $info['type'] === null) {
                    continue;
                }
                $marker = TypeAliases::marker($info['owner'], $info['alias']);
                if (isset($declared[$marker])) {
                    continue;
                }
                $declared[$marker] = [
                    'owner' => $info['owner'],
                    'alias' => $info['alias'],
                    'type' => $info['type'],
                    'class' => $reflection,
                    'context' => $definition['context'] ?? $analysis->context,
                ];
            }
        }

        return $declared;
    }

    /**
     * @param array<string, OA\Schema> $shells
     */
    private function mergeIntoComponents(Analysis $analysis, array $shells): void
    {
        if ([] === $shells) {
            return;
        }

        $components = Undefined::isDefault($analysis->openapi->components)
            ? new OA\Components(['_context' => new Context(['generated' => true], $analysis->context)])
            : $analysis->openapi->components;

        foreach ($shells as $shell) {
            $analysis->mergeAnnotations($components, [$shell], true);
        }

        $analysis->openapi->components = $components;
    }

    /**
     * @param array<string, mixed> $usedNames
     */
    private function uniqueName(Analysis $analysis, string $alias, \ReflectionClass $owner, array $usedNames): string
    {
        $qualified = $owner->getShortName() . '.' . $alias;
        foreach ([$alias, $qualified] as $candidate) {
            if (!$this->nameTaken($analysis, $candidate, $usedNames)) {
                return $candidate;
            }
        }

        $suffix = 2;
        while ($this->nameTaken($analysis, $qualified . $suffix, $usedNames)) {
            ++$suffix;
        }

        return $qualified . $suffix;
    }

    /**
     * @param array<string, mixed> $usedNames
     */
    private function nameTaken(Analysis $analysis, string $name, array $usedNames): bool
    {
        return isset($usedNames[$name]) || $analysis->getSchemaByName($name) instanceof OA\Schema;
    }

    private function schemaClass(OA\Schema $schema): ?\ReflectionClass
    {
        $context = $schema->_context->with('class')
            ?: $schema->_context->with('interface')
            ?: $schema->_context->with('trait');

        return $context && $context->reflector instanceof \ReflectionClass ? $context->reflector : null;
    }

    private function hasBody(OA\Schema $schema): bool
    {
        return !Undefined::isDefault(
            $schema->ref,
            $schema->type,
            $schema->properties,
            $schema->items,
            $schema->additionalProperties,
            $schema->oneOf,
            $schema->allOf,
            $schema->anyOf,
            $schema->enum,
        );
    }
}
