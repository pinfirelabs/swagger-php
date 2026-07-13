<?php declare(strict_types=1);

/**
 * @license Apache-2.0
 */

namespace OpenApi\Tests\Processors;

use OpenApi\Annotations as OA;
use OpenApi\Processors\AugmentProperties;
use OpenApi\Processors\AugmentRefs;
use OpenApi\Processors\AugmentSchemas;
use OpenApi\Processors\ExpandSchemaProperties;
use OpenApi\Processors\MergeIntoComponents;
use OpenApi\Processors\MergeIntoOpenApi;
use OpenApi\Tests\OpenApiTestCase;
use OpenApi\Undefined;

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

    private function schema($analysis, string $name): OA\Schema
    {
        $schema = $analysis->getSchemaByName($name);
        $this->assertInstanceOf(OA\Schema::class, $schema);

        return $schema;
    }
}
