<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Processors;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use OpenApi\Tests\OpenApiTestCase;
use OpenApi\Type\LegacyTypeResolver;
use OpenApi\Type\TypeInfoTypeResolver;
use OpenApi\TypeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExpandTypeAliasesTest extends OpenApiTestCase
{
    private const REF = '#/components/schemas/';

    private const FILES = [
        'PHP/TypeAliases/Order.php',
        'PHP/TypeAliases/OrderItem.php',
        'PHP/TypeAliases/OrderImporter.php',
        'PHP/TypeAliases/Bound.php',
        'PHP/TypeAliases/CollisionA.php',
        'PHP/TypeAliases/CollisionB.php',
    ];

    public static function versions(): iterable
    {
        yield '3.0.0' => [OA\OpenApi::VERSION_3_0_0];
        yield '3.1.0' => [OA\OpenApi::VERSION_3_1_0];
    }

    /**
     * @param string[] $files
     *
     * @return array<string, array<string, mixed>>
     */
    private function schemas(array $files, TypeResolverInterface $resolver, string $version, bool $clean = false): array
    {
        $generator = (new Generator())
            ->setTypeResolver($resolver)
            ->setVersion($version);
        if ($clean) {
            $generator->setConfig(['cleanUnusedComponents' => ['enabled' => true]]);
        }

        $openapi = $generator->generate(self::fixtures($files), null, false);

        return json_decode($openapi->toJson(), true)['components']['schemas'] ?? [];
    }

    #[DataProvider('versions')]
    public function testPromotesArrayShapeAlias(string $version): void
    {
        $schemas = $this->schemas(self::FILES, new TypeInfoTypeResolver(), $version);

        // Required excludes the optional "eventUuid" key; numeric-string maps to string;
        // the member class becomes a $ref.
        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'eventUuid' => ['type' => 'string'],
                'item' => ['$ref' => self::REF . 'OrderItem'],
                'price' => ['type' => 'string'],
                'quantity' => ['type' => 'integer'],
            ],
            'required' => ['item', 'price', 'quantity'],
        ], $schemas['OrderLine']);
    }

    #[DataProvider('versions')]
    public function testPromotesScalarAndAliasOfAlias(string $version): void
    {
        $schemas = $this->schemas(self::FILES, new TypeInfoTypeResolver(), $version);

        $this->assertEquals(['type' => 'string'], $schemas['Uuid']);
        $this->assertEquals([
            'type' => 'array',
            'items' => ['$ref' => self::REF . 'OrderLine'],
        ], $schemas['LineList']);
    }

    #[DataProvider('versions')]
    public function testUsageSiteRefsAtEveryNesting(string $version): void
    {
        $schemas = $this->schemas(self::FILES, new TypeInfoTypeResolver(), $version);
        $properties = $schemas['Order']['properties'];

        $this->assertEquals(['$ref' => self::REF . 'OrderLine'], $properties['line']);
        $this->assertEquals(['type' => 'array', 'items' => ['$ref' => self::REF . 'OrderLine']], $properties['lines']);
        $this->assertEquals(['type' => 'object', 'additionalProperties' => ['$ref' => self::REF . 'OrderLine']], $properties['linesByKey']);
        $this->assertEquals(['$ref' => self::REF . 'Uuid'], $properties['uuid']);
        $this->assertEquals(['$ref' => self::REF . 'LineList'], $properties['lineList']);

        $optionalLine = $version === OA\OpenApi::VERSION_3_0_0
            ? ['oneOf' => [['$ref' => self::REF . 'OrderLine']], 'nullable' => true]
            : ['oneOf' => [['$ref' => self::REF . 'OrderLine'], ['type' => 'null']]];
        $this->assertEquals($optionalLine, $properties['optionalLine']);
    }

    public function testImportedAliasSharesSingleComponent(): void
    {
        $schemas = $this->schemas(self::FILES, new TypeInfoTypeResolver(), OA\OpenApi::VERSION_3_1_0);

        $this->assertArrayNotHasKey('ImportedLine', $schemas);
        $this->assertEquals(
            ['type' => 'array', 'items' => ['$ref' => self::REF . 'OrderLine']],
            $schemas['OrderImporter']['properties']['importedLines'],
        );
    }

    public function testCollisionNaming(): void
    {
        $schemas = $this->schemas(self::FILES, new TypeInfoTypeResolver(), OA\OpenApi::VERSION_3_1_0);

        $this->assertArrayHasKey('Status', $schemas);
        $this->assertArrayHasKey('CollisionB.Status', $schemas);
        $this->assertEquals(['$ref' => self::REF . 'Status'], $schemas['CollisionA']['properties']['status']);
        $this->assertEquals(['$ref' => self::REF . 'CollisionB.Status'], $schemas['CollisionB']['properties']['status']);
    }

    #[DataProvider('versions')]
    public function testTypeAliasBinding(string $version): void
    {
        $schemas = $this->schemas(self::FILES, new TypeInfoTypeResolver(), $version);

        // The alias binds to the user schema: a single named component carrying the
        // user's description plus the alias body — no generated duplicate.
        $this->assertArrayNotHasKey('InvoiceLine', $schemas);
        $this->assertEquals([
            'description' => 'A serialized invoice line',
            'type' => 'object',
            'properties' => [
                'item' => ['$ref' => self::REF . 'OrderItem'],
                'price' => ['type' => 'string'],
            ],
            'required' => ['item', 'price'],
        ], $schemas['InvoiceLineResponse']);
    }

    public function testLegacyResolverPromotesNoAliases(): void
    {
        $schemas = $this->schemas(self::FILES, new LegacyTypeResolver(), OA\OpenApi::VERSION_3_1_0);

        foreach (['OrderLine', 'Uuid', 'LineList', 'Status', 'CollisionB.Status', 'InvoiceLine'] as $absent) {
            $this->assertArrayNotHasKey($absent, $schemas);
        }
        // A usage site is left untyped rather than turned into a $ref.
        $this->assertArrayNotHasKey('$ref', $schemas['Order']['properties']['line']);
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableRef(string $name, string $version): array
    {
        return $version === OA\OpenApi::VERSION_3_0_0
            ? ['oneOf' => [['$ref' => self::REF . $name]], 'nullable' => true]
            : ['oneOf' => [['$ref' => self::REF . $name], ['type' => 'null']]];
    }

    #[DataProvider('versions')]
    public function testPickPoolClassTypedProperties(string $version): void
    {
        $schemas = $this->schemas(
            ['PHP/TypeAliases/MagicProps.php', 'PHP/TypeAliases/OrderItem.php'],
            new TypeInfoTypeResolver(),
            $version,
        );
        $properties = $schemas['Magic']['properties'];

        // Class-typed @property tags become $refs at every nesting position; an
        // alias-typed @property refs the promoted alias component.
        $this->assertEquals(['type' => 'array', 'items' => ['$ref' => self::REF . 'OrderItem']], $properties['list']);
        $this->assertEquals(['$ref' => self::REF . 'OrderItem'], $properties['one']);
        $this->assertEquals($this->nullableRef('OrderItem', $version), $properties['maybe']);
        $this->assertEquals(['$ref' => self::REF . 'ItemList'], $properties['aliased']);
    }

    #[DataProvider('versions')]
    public function testPickPoolClassTypedPropertiesWithoutAliases(string $version): void
    {
        // The RecHub case: class-typed @property tags on a class that declares no
        // aliases at all (empty registry) must still resolve to $refs, not double-arrays.
        $schemas = $this->schemas(
            ['PHP/TypeAliases/NoAliasMagic.php', 'PHP/TypeAliases/OrderItem.php'],
            new TypeInfoTypeResolver(),
            $version,
        );
        $properties = $schemas['NoAliasMagic']['properties'];

        $this->assertEquals(['type' => 'array', 'items' => ['$ref' => self::REF . 'OrderItem']], $properties['list']);
        $this->assertEquals(['$ref' => self::REF . 'OrderItem'], $properties['one']);
        $this->assertEquals($this->nullableRef('OrderItem', $version), $properties['maybe']);
    }

    public function testCleanUnusedComponentsPrunesAlias(): void
    {
        $files = ['PHP/TypeAliases/OrphanAlias.php'];

        $kept = $this->schemas($files, new TypeInfoTypeResolver(), OA\OpenApi::VERSION_3_1_0);
        $this->assertArrayHasKey('Orphan', $kept);

        $pruned = $this->schemas($files, new TypeInfoTypeResolver(), OA\OpenApi::VERSION_3_1_0, true);
        $this->assertArrayNotHasKey('Orphan', $pruned);
    }
}
