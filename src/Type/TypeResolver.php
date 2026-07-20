<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Type;

use OpenApi\Utils\TypeMapper;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Radebatz\TypeInfoExtras\Type\ExplicitType;
use Radebatz\TypeInfoExtras\Type\IntRangeType;
use Radebatz\TypeInfoExtras\TypeResolver\StringTypeResolver;
use Symfony\Component\TypeInfo\Exception\ExceptionInterface;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ArrayShapeType;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\CompositeTypeInterface;
use Symfony\Component\TypeInfo\Type\IntersectionType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\TypeInfo\TypeContext\TypeContext;
use Symfony\Component\TypeInfo\TypeContext\TypeContextFactory;
use Symfony\Component\TypeInfo\TypeResolver\ReflectionTypeResolver;

/**
 * Resolves a PHP reflector to a SchemaType value object.
 *
 * Shared core between the spec-attributes augmenter and the classic annotation resolver.
 * Uses Symfony TypeInfo + phpstan/phpdoc-parser for reflective type resolution.
 */
class TypeResolver
{
    protected TypeMapper $typeMapper;

    protected TypeContextFactory $typeContextFactory;

    protected TypeContextFactory $bareTypeContextFactory;

    protected StringTypeResolver $stringTypeResolver;

    protected Lexer $phpDocLexer;

    protected PhpDocParser $phpDocParser;

    protected TypeAliases $typeAliases;

    public function __construct()
    {
        $this->typeMapper = new TypeMapper();
        $this->typeContextFactory = new TypeContextFactory(new \Symfony\Component\TypeInfo\TypeResolver\StringTypeResolver());
        $this->bareTypeContextFactory = new TypeContextFactory();
        $this->stringTypeResolver = new StringTypeResolver();
        $this->typeAliases = new TypeAliases();

        $config = new ParserConfig([]);
        $constExprParser = new ConstExprParser($config);
        $this->phpDocLexer = new Lexer($config);
        $this->phpDocParser = new PhpDocParser(
            $config,
            new TypeParser($config, $constExprParser),
            $constExprParser,
        );
    }

    /**
     * Create a type-alias-aware TypeContext for the given reflector, falling back to a
     * plain context (no @phpstan-type/@psalm-type alias collection) if alias resolution fails.
     */
    protected function createTypeContext(\Reflector $reflector): ?TypeContext
    {
        try {
            return $this->typeContextFactory->createFromReflection($reflector);
        } catch (ExceptionInterface) {
            return $this->bareTypeContextFactory->createFromReflection($reflector);
        }
    }

    /**
     * Resolve the PHP type of a reflector into a SchemaType.
     *
     * @param \ReflectionProperty|\ReflectionParameter|\ReflectionMethod|\ReflectionClassConstant $reflector
     * @param array<string, mixed>                                                                $liveMarkers registered alias markers (see TypeAliases) that should resolve to $refs
     */
    public function resolve(\Reflector $reflector, array $liveMarkers = []): ?SchemaType
    {
        $docblockType = $this->getDocblockType($reflector, $liveMarkers);
        $reflectionType = $this->getReflectionType($reflector);

        if (!$docblockType && !$reflectionType) {
            return null;
        }

        $nullable = null;
        if (($docblockType && $docblockType->isNullable()) || ($reflectionType && $reflectionType->isNullable())) {
            $nullable = true;
        }

        $docblockType = $docblockType instanceof NullableType ? $docblockType->getWrappedType() : $docblockType;
        $reflectionType = $reflectionType instanceof NullableType ? $reflectionType->getWrappedType() : $reflectionType;

        $effectiveType = $docblockType ?? $reflectionType;
        if (!$effectiveType instanceof Type) {
            return $nullable !== null ? new SchemaType(nullable: $nullable) : null;
        }

        $result = $this->mapType($effectiveType);
        $result->nullable = $nullable;

        $this->applyNativeTypeMapping($result);

        return $result;
    }

    /**
     * Resolve a PHPDoc type string in the namespace/import context of a
     * reflector. Used for virtual class properties declared with @property and
     * for promoted type aliases.
     *
     * When <code>$aliasMarkers</code> is true and the reflector belongs to a class
     * that declares registered PHPStan/Psalm type aliases, alias usages resolve to
     * markers that later become <code>$ref</code>s; otherwise aliases expand inline.
     *
     * @param array<string, mixed> $liveMarkers registered alias markers (see TypeAliases)
     */
    public function resolveTypeString(string $type, \Reflector $reflector, bool $aliasMarkers = true, array $liveMarkers = []): ?SchemaType
    {
        try {
            $typeContext = $this->createTypeContext($reflector);
            if ($aliasMarkers && ($class = $this->declaringClass($reflector)) instanceof \ReflectionClass) {
                $typeContext = $this->createAliasMarkerContext($class, $typeContext, $liveMarkers);
            }
            $resolved = $this->stringTypeResolver->resolve($type, $typeContext);
        } catch (\Throwable) {
            return null;
        }

        $nullable = $resolved instanceof NullableType;
        $resolved = $resolved instanceof NullableType ? $resolved->getWrappedType() : $resolved;

        $result = $this->mapType($resolved);
        $result->nullable = $nullable ?: null;
        $this->applyNativeTypeMapping($result);

        return $result;
    }

    /**
     * Overlay alias markers onto a TypeContext so that usages of the class'
     * registered PHPStan/Psalm type aliases resolve to marker object types.
     *
     * Only aliases whose marker is present in <code>$liveMarkers</code> are seeded, so
     * alias usages expand inline until the matching component has been registered.
     * Markers win over any inline alias definitions collected by the factory.
     *
     * @param array<string, mixed> $liveMarkers
     */
    public function createAliasMarkerContext(\ReflectionClass $class, ?TypeContext $base = null, array $liveMarkers = []): ?TypeContext
    {
        $base ??= $this->createTypeContext($class);
        if (!$base instanceof TypeContext) {
            return null;
        }

        $markers = [];
        foreach ($this->typeAliases->forClass($class) as $local => $info) {
            if ($info['templated'] || $info['type'] === null) {
                continue;
            }
            $marker = TypeAliases::marker($info['owner'], $info['alias']);
            if (!array_key_exists($marker, $liveMarkers)) {
                continue;
            }
            $markers[$local] = Type::object($marker);
        }

        if ([] === $markers) {
            return $base;
        }

        return new TypeContext(
            $base->calledClassName,
            $base->declaringClassName,
            $base->namespace,
            $base->uses,
            $base->templates,
            $markers + $base->typeAliases,
        );
    }

    protected function declaringClass(\Reflector $reflector): ?\ReflectionClass
    {
        return match (true) {
            $reflector instanceof \ReflectionClass => $reflector,
            $reflector instanceof \ReflectionProperty,
            $reflector instanceof \ReflectionMethod,
            $reflector instanceof \ReflectionClassConstant => $reflector->getDeclaringClass(),
            $reflector instanceof \ReflectionParameter => $reflector->getDeclaringFunction() instanceof \ReflectionMethod
                ? $reflector->getDeclaringFunction()->getDeclaringClass()
                : null,
            default => null,
        };
    }

    /**
     * Extract virtual properties from a class-level PHPDoc block, including
     * any declared on ancestor classes' docblocks.
     *
     * The pool a subclass can `pick` from spans the full inheritance chain: a
     * base class (e.g. an ActiveRecord base) may declare `@property-read $id`
     * that derived classes rely on. Walking child->parent, the most-derived
     * declaration of a given property name wins on collision.
     *
     * @return list<array{name: string, type: string, description: string, readOnly: bool, writeOnly: bool}>
     */
    public function getDocblockProperties(\ReflectionClass $class): array
    {
        $properties = [];
        $seen = [];
        for ($current = $class; $current instanceof \ReflectionClass; $current = $current->getParentClass() ?: null) {
            foreach ($this->getLocalDocblockProperties($current) as $property) {
                if (isset($seen[$property['name']])) {
                    // a more-derived class already declared this name; keep it
                    continue;
                }
                $seen[$property['name']] = true;
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /**
     * Extract virtual properties declared on a single class' own docblock.
     *
     * @return list<array{name: string, type: string, description: string, readOnly: bool, writeOnly: bool}>
     */
    private function getLocalDocblockProperties(\ReflectionClass $class): array
    {
        $docComment = $class->getDocComment();
        if (!$docComment) {
            return [];
        }

        $lexer = new Lexer(new ParserConfig([]));
        $config = new ParserConfig([]);
        $constExprParser = new ConstExprParser($config);
        $phpDocParser = new PhpDocParser(
            $config,
            new TypeParser($config, $constExprParser),
            $constExprParser,
        );
        $tokens = new TokenIterator($lexer->tokenize($docComment));
        $docNode = $phpDocParser->parse($tokens);

        $properties = [];
        foreach (['@property' => [false, false], '@property-read' => [true, false], '@property-write' => [false, true]] as $tagName => [$readOnly, $writeOnly]) {
            foreach ($docNode->getTagsByName($tagName) as $tag) {
                if (!$tag->value instanceof PropertyTagValueNode) {
                    continue;
                }
                $properties[] = [
                    'name' => ltrim($tag->value->propertyName, '$'),
                    'type' => (string) $tag->value->type,
                    // a paragraph break ends the description; later paragraphs are unrelated
                    // docblock prose the parser treats as tag continuation (split before
                    // trimming — a leading break means the tag has no description at all)
                    'description' => trim(explode("\n\n", $tag->value->description, 2)[0]),
                    'readOnly' => $readOnly,
                    'writeOnly' => $writeOnly,
                ];
            }
        }

        return $properties;
    }

    protected function isMixedType(Type $type): bool
    {
        return $type instanceof BuiltinType && 'mixed' === (string) $type;
    }

    protected function mapType(Type $type): SchemaType
    {
        if ($type instanceof CompositeTypeInterface) {
            return $this->mapCompositeType($type);
        }

        if ($type instanceof BuiltinType) {
            return $this->mapBuiltinType($type);
        }

        if ($type instanceof ObjectType) {
            return new SchemaType(type: $type->getClassName());
        }

        if ($type instanceof IntRangeType) {
            return new SchemaType(
                type: $type->getTypeIdentifier()->value,
                minimum: $type->getFrom(),
                maximum: $type->getTo(),
            );
        }

        if ($type instanceof ExplicitType) {
            return new SchemaType(type: $type->getTypeIdentifier()->value);
        }

        if ($type instanceof ArrayShapeType && [] !== $type->getShape()) {
            return $this->mapArrayShape($type);
        }

        if ($type instanceof CollectionType) {
            // TypeInfo coerces Traversable/ArrayAccess implementors (e.g. active-record
            // models) into collections; without explicit value generics the class itself
            // is the schema subject, not a container.
            $wrapped = $type->getWrappedType();
            if ($wrapped instanceof ObjectType && $this->isMixedType($type->getCollectionValueType())) {
                return new SchemaType(type: $wrapped->getClassName());
            }

            return $this->mapCollectionType($type);
        }

        return new SchemaType();
    }

    protected function mapCompositeType(CompositeTypeInterface $type): SchemaType
    {
        $types = $type->getTypes();

        $isNonZeroInt = 2 === count($types) && $types[0] instanceof IntRangeType && $types[1] instanceof IntRangeType;
        if ($isNonZeroInt) {
            return new SchemaType(type: 'int', not: ['const' => 0]);
        }

        $allBuiltin = array_reduce($types, static fn ($carry, $t): bool => $carry && $t instanceof BuiltinType, true);

        if ($type instanceof UnionType) {
            if ($allBuiltin) {
                $mappableTypes = array_values(array_filter(
                    array_map(static fn (Type $t): string => (string) $t, $types),
                    $this->typeMapper->hasOpenApiType(...),
                ));

                return new SchemaType(type: [] === $mappableTypes ? null : $mappableTypes);
            }

            $builtinTypes = array_filter($types, static fn (Type $t): bool => $t instanceof BuiltinType);
            $otherTypes = array_filter($types, static fn (Type $t): bool => !$t instanceof BuiltinType);

            $oneOf = [];
            if ($builtinTypes !== []) {
                $builtinSchema = new SchemaType(
                    type: array_values(array_map(static fn (Type $t): string => (string) $t, $builtinTypes)),
                );
                $this->applyNativeTypeMapping($builtinSchema);
                $oneOf[] = $builtinSchema;
            }

            foreach ($otherTypes as $otherType) {
                $oneOf[] = $this->mapType($otherType);
            }

            return new SchemaType(oneOf: $oneOf);
        }

        if ($type instanceof IntersectionType) {
            $allOf = [];
            foreach ($types as $intersectionType) {
                $allOf[] = $this->mapType($intersectionType);
            }

            return new SchemaType(allOf: $allOf);
        }

        return new SchemaType();
    }

    protected function mapBuiltinType(BuiltinType $type): SchemaType
    {
        $typeName = (string) $type;
        if ($this->typeMapper->hasOpenApiType($typeName)) {
            return new SchemaType(type: $typeName);
        }

        return new SchemaType();
    }

    protected function mapCollectionType(CollectionType $type): SchemaType
    {
        if ($type->isList() || $type->getCollectionKeyType() instanceof UnionType) {
            $itemType = $this->mapType($type->getCollectionValueType());
            $this->applyNativeTypeMapping($itemType);

            return new SchemaType(type: 'array', items: $itemType);
        }

        $valueSchema = $this->mapType($type->getCollectionValueType());
        $this->applyNativeTypeMapping($valueSchema);

        return new SchemaType(type: 'object', additionalProperties: $valueSchema);
    }

    protected function mapArrayShape(ArrayShapeType $type): SchemaType
    {
        $shape = $type->getShape();

        if (array_is_list($shape)) {
            $itemType = $this->mapType($type->getCollectionValueType());
            $this->applyNativeTypeMapping($itemType);

            return new SchemaType(type: 'array', items: $itemType);
        }

        $properties = [];
        $required = [];
        foreach ($shape as $name => $member) {
            $propertyName = (string) $name;
            $propertySchema = $this->mapType($member['type']);
            $this->applyNativeTypeMapping($propertySchema);
            $properties[$propertyName] = $propertySchema;

            if (!($member['optional'] ?? false)) {
                $required[] = $propertyName;
            }
        }

        $result = new SchemaType(type: 'object', properties: $properties);
        if ([] !== $required) {
            $result->required = $required;
        }

        if ($type->getExtraValueType() instanceof Type) {
            $result->additionalProperties = true;
        }

        return $result;
    }

    /**
     * Apply native PHP type to OpenAPI type/format mapping in-place.
     */
    protected function applyNativeTypeMapping(SchemaType $schema): void
    {
        if (is_string($schema->type)) {
            $mapped = $this->typeMapper->map($schema->type);
            if ($mapped === null) {
                // not a native type — leave as-is (likely a FQCN for ref resolution)
            } elseif ('mixed' === $mapped['type']) {
                $schema->type = null;
            } else {
                $schema->type = $mapped['type'];
                if ($mapped['format'] !== null && $schema->format === null) {
                    $schema->format = $mapped['format'];
                }
            }
        } elseif (is_array($schema->type)) {
            $schema->type = $this->typeMapper->toSpecTypes(
                array_map(static fn ($t): string => strtolower((string) $t), $schema->type),
            );
        }
    }

    /**
     * @param \ReflectionProperty|\ReflectionParameter|\ReflectionMethod|\ReflectionClass $reflector
     */
    protected function getReflectionType(\Reflector $reflector): ?Type
    {
        $subject = $reflector instanceof \ReflectionClass
            ? $reflector->getName()
            : (
                $reflector instanceof \ReflectionMethod
                ? $reflector->getReturnType()
                : (method_exists($reflector, 'getType') ? $reflector->getType() : null)
            );

        try {
            $typeContext = $this->createTypeContext($reflector);

            return (new ReflectionTypeResolver())->resolve($subject, $typeContext);
        } catch (UnsupportedException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $liveMarkers registered alias markers (see TypeAliases)
     */
    public function getDocblockType(\Reflector $reflector, array $liveMarkers = []): ?Type
    {
        $docComment = match (true) {
            $reflector instanceof \ReflectionProperty => $reflector->isPromoted()
                && $reflector->getDeclaringClass() && $reflector->getDeclaringClass()->getConstructor()
                    ? $reflector->getDeclaringClass()->getConstructor()->getDocComment()
                    : $reflector->getDocComment(),
            $reflector instanceof \ReflectionParameter => $reflector->getDeclaringFunction()->getDocComment(),
            $reflector instanceof \ReflectionFunctionAbstract => $reflector->getDocComment(),
            default => null,
        };

        if (!$docComment) {
            return null;
        }

        $typeContext = $this->createTypeContext($reflector);
        if ([] !== $liveMarkers && ($class = $this->declaringClass($reflector)) instanceof \ReflectionClass) {
            $typeContext = $this->createAliasMarkerContext($class, $typeContext, $liveMarkers);
        }

        $tagName = match (true) {
            $reflector instanceof \ReflectionProperty => $reflector->isPromoted()
                ? '@param'
                : '@var',
            $reflector instanceof \ReflectionParameter => '@param',
            $reflector instanceof \ReflectionFunctionAbstract => '@return',
            default => null,
        };

        if ($tagName === null) {
            return null;
        }

        $tokens = new TokenIterator($this->phpDocLexer->tokenize($docComment));
        $docNode = $this->phpDocParser->parse($tokens);

        foreach ($docNode->getTagsByName($tagName) as $tag) {
            $tagValue = $tag->value;

            if (
                $tagValue instanceof VarTagValueNode
                || ($tagValue instanceof ParamTagValueNode && '$' . $reflector->getName() === $tagValue->parameterName)
                || $tagValue instanceof ReturnTagValueNode
            ) {
                try {
                    return $this->stringTypeResolver->resolve((string) $tagValue, $typeContext);
                } catch (UnsupportedException) {
                    // ignore
                }
            }
        }

        return null;
    }
}
