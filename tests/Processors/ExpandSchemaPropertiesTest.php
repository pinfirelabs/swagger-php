<?php declare(strict_types=1);

/**
 * @license Apache-2.0
 */

namespace OpenApi\Tests\Processors;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use OpenApi\Processors\AugmentProperties;
use OpenApi\Processors\AugmentRefs;
use OpenApi\Processors\AugmentSchemas;
use OpenApi\Processors\ExpandSchemaProperties;
use OpenApi\Processors\ExpandTypeAliases;
use OpenApi\Processors\MergeIntoComponents;
use OpenApi\Processors\MergeIntoOpenApi;
use OpenApi\Tests\OpenApiTestCase;
use OpenApi\Undefined;
use OpenApi\Utils\Pipeline;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExpandSchemaPropertiesTest extends OpenApiTestCase
{
    public function testExpandsVirtualPropertiesAndProjectionInheritance(): void
    {
        $analysis = $this->analysisFromFixtures(['ExpandedSchemaProperties.php'], $this->processorPipeline([
            new MergeIntoOpenApi(),
            new MergeIntoComponents(),
            new AugmentSchemas(),
            new ExpandSchemaProperties(),
            new AugmentProperties(),
            new AugmentRefs(),
        ]));

        $base = $this->schema($analysis, 'VirtualUser');
        $this->assertSame(['id', 'name', 'active'], array_map(static fn (OA\Property $property): string => $property->property, $base->properties));
        $this->assertSame(['id'], $base->required);
        $this->assertSame('integer', $base->properties[0]->type);
        $this->assertSame('Internal user identifier', $base->properties[0]->description);
        $this->assertTrue($base->properties[2]->readOnly);

        $withChildren = $this->schema($analysis, 'VirtualUserWithChildren');
        $this->assertSame(Undefined::UNDEFINED, $withChildren->properties);
        $this->assertCount(2, $withChildren->allOf);
        $this->assertSame('#/components/schemas/VirtualUser', $withChildren->allOf[0]->ref);
        $children = $withChildren->allOf[1]->properties[0];
        $this->assertSame('children', $children->property);
        $this->assertSame('array', $children->type);
        $this->assertSame('#/components/schemas/VirtualUser', $children->items->ref);

        $withoutName = $this->schema($analysis, 'VirtualUserWithoutName');
        $this->assertSame(Undefined::UNDEFINED, $withoutName->allOf);
        $this->assertSame(['id', 'active', 'children'], array_map(static fn (OA\Property $property): string => $property->property, $withoutName->properties));
        $this->assertSame(['id'], $withoutName->required);

        $formattedName = $this->schema($analysis, 'VirtualUserFormattedName')->properties[0];
        $this->assertSame('name', $formattedName->property);
        $this->assertSame('string', $formattedName->type);
        $this->assertSame('Display name', $formattedName->description);
        $this->assertSame('email', $formattedName->format);
    }

    public function testDocblockAnnotationsUseTheSameProjectionSyntax(): void
    {
        $analysis = $this->analysisFromFixtures(['ExpandedSchemaProperties.php'], $this->processorPipeline([
            new MergeIntoOpenApi(),
            new MergeIntoComponents(),
            new AugmentSchemas(),
            new ExpandSchemaProperties(),
            new AugmentProperties(),
            new AugmentRefs(),
        ]));

        $withChildren = $this->schema($analysis, 'DocblockVirtualUserWithChildren');
        $this->assertSame('#/components/schemas/DocblockVirtualUser', $withChildren->allOf[0]->ref);
        $children = $withChildren->allOf[1]->properties[0];
        $this->assertSame('#/components/schemas/DocblockVirtualUser', $children->items->ref);
    }

    public function testPropertyWriteTagSetsWriteOnlyAndDescription(): void
    {
        $analysis = $this->analysisFromFixtures(['ExpandedSchemaProperties.php'], $this->processorPipeline($this->defaultPipeline()));

        $writable = $this->schema($analysis, 'WritableAccount');
        $this->assertSame(['id', 'secret'], array_map(static fn (OA\Property $property): string => $property->property, $writable->properties));
        $secret = $writable->properties[1];
        $this->assertSame('Setter-only secret', $secret->description);
        $this->assertTrue($secret->writeOnly);
        $this->assertSame(Undefined::UNDEFINED, $secret->readOnly);

        // @property-write via docblock annotations behaves identically.
        $docblockWritable = $this->schema($analysis, 'DocblockWritableAccount');
        $docblockSecret = $docblockWritable->properties[1];
        $this->assertSame('secret', $docblockSecret->property);
        $this->assertSame('Setter-only secret', $docblockSecret->description);
        $this->assertTrue($docblockSecret->writeOnly);
    }

    public function testOmitRemovesInheritedExplicitProperty(): void
    {
        $analysis = $this->analysisFromFixtures(['ExpandedSchemaProperties.php'], $this->processorPipeline($this->defaultPipeline()));

        $base = $this->schema($analysis, 'BaseWithExplicitFlag');
        $this->assertSame(['flag', 'label'], array_map(static fn (OA\Property $property): string => $property->property, $base->properties));

        $derived = $this->schema($analysis, 'DerivedOmitsExplicitFlag');
        $this->assertSame(Undefined::UNDEFINED, $derived->allOf, 'omit forces flattening even though only one property is left');
        $this->assertSame(['label'], array_map(static fn (OA\Property $property): string => $property->property, $derived->properties));
    }

    public function testRenamePickedPropertyAndRequiredList(): void
    {
        $analysis = $this->analysisFromFixtures(['ExpandedSchemaProperties.php'], $this->processorPipeline($this->defaultPipeline()));

        foreach (['WritableAccountRenamed', 'DocblockWritableAccountRenamed'] as $name) {
            $renamed = $this->schema($analysis, $name);
            $this->assertSame(['id', 'displayName'], array_map(static fn (OA\Property $property): string => $property->property, $renamed->properties), $name);
            $this->assertSame(['id', 'displayName'], $renamed->required, $name . ': required list is renamed along with the property');
            $this->assertSame('Display name', $renamed->properties[1]->description, $name);
        }
    }

    public function testBaseCompositionOutputStrategyIsStableAcrossVersions(): void
    {
        foreach ([OA\OpenApi::VERSION_3_0_0, OA\OpenApi::VERSION_3_1_0] as $version) {
            $analysis = $this->analysisAtVersion(['ExpandedSchemaProperties.php'], $version);

            $composed = $this->toArray($this->schema($analysis, 'PinComposed'));
            $this->assertSame([
                'schema' => 'PinComposed',
                'allOf' => [
                    ['$ref' => '#/components/schemas/PinBase'],
                    [
                        'properties' => [
                            'label' => ['description' => 'Label text', 'type' => 'string'],
                        ],
                        'type' => 'object',
                    ],
                ],
            ], $composed, 'base + pick-only allOf shape for ' . $version);

            $flattened = $this->toArray($this->schema($analysis, 'PinFlattened'));
            $this->assertSame([
                'schema' => 'PinFlattened',
                'properties' => [
                    'label' => ['description' => 'Label text', 'type' => 'string'],
                ],
            ], $flattened, 'base + omit flattened shape for ' . $version);
        }
    }

    public function testPickingUnknownPropertyLogsWarningAndOmitsIt(): void
    {
        // ExpandedSchemaPropertiesDocblock.php also carries the base-cycle
        // fixture (see testBaseCycleLogsErrorAndDoesNotRecurseInfinitely);
        // both classes are scanned together whenever the file is loaded, so
        // both log lines fire regardless of which schema this test cares
        // about.
        $this->assertOpenApiLogEntryContains('picks unknown property "doesNotExist"');
        $this->assertOpenApiLogEntryContains('Schema projection base cycle detected for');

        $analysis = $this->analysisFromFixtures(['ExpandedSchemaPropertiesDocblock.php'], $this->processorPipeline($this->defaultPipeline()));

        $schema = $this->schema($analysis, 'DocblockUnknownPickUser');
        $this->assertSame(['id'], array_map(static fn (OA\Property $property): string => $property->property, $schema->properties));
    }

    public function testBaseCycleLogsErrorAndDoesNotRecurseInfinitely(): void
    {
        // See the comment in testPickingUnknownPropertyLogsWarningAndOmitsIt:
        // both fixtures in this file are scanned together.
        $this->assertOpenApiLogEntryContains('picks unknown property "doesNotExist"');
        $this->assertOpenApiLogEntryContains('Schema projection base cycle detected for');

        $analysis = $this->analysisFromFixtures(['ExpandedSchemaPropertiesDocblock.php'], $this->processorPipeline($this->defaultPipeline()));

        $cycleA = $this->schema($analysis, 'CycleA');
        $cycleB = $this->schema($analysis, 'CycleB');
        $this->assertSame('#/components/schemas/CycleB', $cycleA->allOf[0]->ref);
        $this->assertSame('#/components/schemas/CycleA', $cycleB->allOf[0]->ref);
    }

    public function testSchemaWithoutProjectionFieldsDoesNotImportPropertyTags(): void
    {
        $analysis = $this->analysisFromFixtures(['ExpandedSchemaProperties.php'], $this->processorPipeline($this->defaultPipeline()));

        $plain = $this->schema($analysis, 'PlainWritableAccount');
        $this->assertSame(Undefined::UNDEFINED, $plain->properties, 'bare @OA\Schema on a class with @property tags imports nothing');
    }

    public function testExplicitRequiredEntriesSurvivePickWithoutMatchingProperty(): void
    {
        $analysis = $this->analysisFromFixtures(['ExpandedSchemaProperties.php'], $this->processorPipeline($this->defaultPipeline()));

        $schema = $this->schema($analysis, 'PhantomRequired');
        $this->assertSame(['id', 'label'], array_map(static fn (OA\Property $property): string => $property->property, $schema->properties));
        // JSON Schema allows requiring keys the schema does not describe
        $this->assertSame(['id', 'phantom_column'], $schema->required);
    }

    public function testExpandsSchemasNestedUnderComponents(): void
    {
        $analysis = $this->analysisFromFixtures(['ExpandedSchemaPropertiesComponents.php'], $this->processorPipeline([
            new MergeIntoOpenApi(),
            new MergeIntoComponents(),
            new AugmentSchemas(),
            new ExpandTypeAliases(),
            new ExpandSchemaProperties(),
            new AugmentProperties(),
            new AugmentRefs(),
        ]));

        $derived = $this->schema($analysis, 'ProjectionValidationError');
        $this->assertSame(Undefined::UNDEFINED, $derived->properties);
        $this->assertSame(Undefined::UNDEFINED, $derived->type, 'no stray top-level type next to the composed allOf');
        $this->assertCount(2, $derived->allOf);
        $this->assertSame('#/components/schemas/ProjectionError', $derived->allOf[0]->ref);
        $override = $derived->allOf[1]->properties[0];
        $this->assertSame('error', $override->property);
        $this->assertSame(['ValidationError'], $override->enum);

        $bound = $this->schema($analysis, 'ProblemDetails');
        $this->assertSame('Problem details', $bound->description);
        $this->assertSame('object', $bound->type);
        $this->assertEqualsCanonicalizing(['title', 'code'], array_map(static fn (OA\Property $property): string => $property->property, $bound->properties));
        $this->assertSame(['title'], $bound->required);
    }

    public static function versions(): iterable
    {
        yield '3.0.0' => [OA\OpenApi::VERSION_3_0_0];
        yield '3.1.0' => [OA\OpenApi::VERSION_3_1_0];
    }

    #[DataProvider('versions')]
    public function testPicksVirtualPropertyDeclaredOnAncestorDocblock(string $version): void
    {
        $analysis = $this->analysisAtVersion(['ExpandedSchemaProperties.php'], $version);

        $equipment = $this->schema($analysis, 'InheritedEquipment');
        $this->assertSame(['id', 'createdAt', 'name'], array_map(static fn (OA\Property $property): string => $property->property, $equipment->properties), $version);

        [$id, $createdAt, $name] = $equipment->properties;

        // @property-read $id declared only on the base class docblock
        $this->assertSame('integer', $id->type, $version);
        $this->assertSame('Primary key', $id->description, $version);
        $this->assertTrue($id->readOnly, $version);

        // @property $createdAt declared only on the base class docblock
        $this->assertSame('string', $createdAt->type, $version);
        $this->assertSame('Creation timestamp', $createdAt->description, $version);
        $this->assertSame(Undefined::UNDEFINED, $createdAt->readOnly, $version);

        // $name declared on the subclass' own docblock
        $this->assertSame('string', $name->type, $version);
        $this->assertSame('Equipment name', $name->description, $version);
    }

    #[DataProvider('versions')]
    public function testSubclassDocblockTagOverridesAncestorTag(string $version): void
    {
        $analysis = $this->analysisAtVersion(['ExpandedSchemaProperties.php'], $version);

        $value = $this->schema($analysis, 'OverrideChild')->properties[0];
        $this->assertSame('value', $value->property, $version);

        // the subclass' `@property string $value` wins over the base's `@property-read int $value`
        $this->assertSame('string', $value->type, $version);
        $this->assertSame('Overridden string value', $value->description, $version);
        $this->assertSame(Undefined::UNDEFINED, $value->readOnly, $version);
    }

    #[DataProvider('versions')]
    public function testPicksVirtualPropertiesAcrossMultipleAncestorLevels(string $version): void
    {
        $analysis = $this->analysisAtVersion(['ExpandedSchemaProperties.php'], $version);

        $child = $this->schema($analysis, 'MultiLevelChild');
        $this->assertSame(['grandId', 'parentField', 'childFlag'], array_map(static fn (OA\Property $property): string => $property->property, $child->properties), $version);

        [$grandId, $parentField, $childFlag] = $child->properties;

        // declared two levels up (grandparent docblock)
        $this->assertSame('integer', $grandId->type, $version);
        $this->assertSame('Grandparent id', $grandId->description, $version);
        $this->assertTrue($grandId->readOnly, $version);

        // declared one level up (parent docblock)
        $this->assertSame('string', $parentField->type, $version);
        $this->assertSame('Parent field', $parentField->description, $version);

        // declared on the child's own docblock
        $this->assertSame('boolean', $childFlag->type, $version);
        $this->assertSame('Child flag', $childFlag->description, $version);
    }

    /**
     * @return array<int,object>
     */
    private function defaultPipeline(): array
    {
        return [
            new MergeIntoOpenApi(),
            new MergeIntoComponents(),
            new AugmentSchemas(),
            new ExpandSchemaProperties(),
            new AugmentProperties(),
            new AugmentRefs(),
        ];
    }

    private function analysisAtVersion(array $files, string $version): Analysis
    {
        $analysis = new Analysis([], $this->getContext());
        (new Generator($this->getTrackingLogger()))
            ->setVersion($version)
            ->setAnalyser($this->getAnalyzer())
            ->setTypeResolver($this->getTypeResolver())
            ->setProcessorPipeline(new Pipeline($this->defaultPipeline()))
            ->generate($this->fixtures($files), $analysis, false);

        return $analysis;
    }

    private function toArray(OA\Schema $schema): array
    {
        return json_decode(json_encode($schema), true);
    }

    private function schema(Analysis $analysis, string $name): OA\Schema
    {
        $schema = $analysis->getSchemaByName($name);
        $this->assertInstanceOf(OA\Schema::class, $schema);

        return $schema;
    }
}
