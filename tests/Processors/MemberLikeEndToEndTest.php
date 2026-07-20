<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Processors;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use OpenApi\Tests\OpenApiTestCase;
use OpenApi\Type\TypeInfoTypeResolver;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * End-to-end acceptance coverage for the compact-schema feature set, run through the
 * FULL default Generator pipeline over a Yii-1-style fixture: docblock @OA\ annotations
 * on a global-namespace-style ActiveRecord class, @property/@property-read/@property-write
 * columns, a @phpstan-type alias, and a curated three-schema (base/pick/omit) projection
 * with a @OA\Components block nested on the same class.
 */
final class MemberLikeEndToEndTest extends OpenApiTestCase
{
    private const REF = '#/components/schemas/';

    private const FILES = [
        'PHP/TypeAliases/MemberLike.php',
        'PHP/TypeAliases/MemberLikeClub.php',
    ];

    public static function versions(): iterable
    {
        yield '3.0.0' => [OA\OpenApi::VERSION_3_0_0];
        yield '3.1.0' => [OA\OpenApi::VERSION_3_1_0];
    }

    /**
     * @return array<string, mixed>
     */
    private function components(string $version): array
    {
        $generator = (new Generator())
            ->setTypeResolver(new TypeInfoTypeResolver())
            ->setVersion($version);

        $openapi = $generator->generate(self::fixtures(self::FILES), null, false);

        return json_decode($openapi->toJson(), true)['components'] ?? [];
    }

    /**
     * A `$ref` sibling `description` is only emitted under 3.1; 3.0 keeps `$ref` bare.
     *
     * @return array<string, mixed>
     */
    private function ref(string $name, string $version, string $description): array
    {
        $ref = ['$ref' => self::REF . $name];
        if ($version === OA\OpenApi::VERSION_3_1_0) {
            $ref['description'] = $description;
        }

        return $ref;
    }

    #[DataProvider('versions')]
    public function testCreateSchemaIsFlatWithPickedPropertiesAndRequired(string $version): void
    {
        $schemas = $this->components($version)['schemas'];

        // No base: a flat schema. Descriptions come from the @property tag text, the
        // tinyint-style flag maps to type boolean, and the alias-typed column is a $ref
        // to the promoted alias component.
        $this->assertEquals([
            'required' => ['firstName', 'lastName'],
            'properties' => [
                'firstName' => ['description' => 'First name', 'type' => 'string'],
                'lastName' => ['description' => 'Last name', 'type' => 'string'],
                'isActive' => ['description' => 'Active flag', 'type' => 'boolean'],
                'address' => $this->ref('AddressShape', $version, 'Mailing address'),
            ],
        ], $schemas['CreateMemberLike']);
    }

    #[DataProvider('versions')]
    public function testDerivedSchemaComposesAllOfWithFormatOverrideAndReadOnly(string $version): void
    {
        $schemas = $this->components($version)['schemas'];

        $this->assertEquals([
            'allOf' => [
                ['$ref' => self::REF . 'CreateMemberLike'],
                [
                    'type' => 'object',
                    'properties' => [
                        // The explicit nested @OA\Property(format="int64") overrides the
                        // picked, non-readOnly column.
                        'id' => ['description' => 'Member id', 'type' => 'integer', 'format' => 'int64'],
                        // A @property-read pick becomes readOnly automatically.
                        'fullName' => ['description' => 'Computed full name', 'type' => 'string', 'readOnly' => true],
                    ],
                ],
            ],
        ], $schemas['MemberLike']);
    }

    #[DataProvider('versions')]
    public function testFullSchemaFlattensOmitsAndResolvesRelations(string $version): void
    {
        $schemas = $this->components($version)['schemas'];
        $full = $schemas['MemberLikeFull'];

        // omit={"firstName"} removes both the property and its required entry, even
        // though "firstName" was required two base levels up, in CreateMemberLike.
        $this->assertArrayNotHasKey('firstName', $full['properties']);
        $this->assertSame(['lastName'], $full['required']);

        $this->assertEquals([
            'lastName' => ['description' => 'Last name', 'type' => 'string'],
            'isActive' => ['description' => 'Active flag', 'type' => 'boolean'],
            'address' => $this->ref('AddressShape', $version, 'Mailing address'),
            'id' => ['description' => 'Member id', 'type' => 'integer', 'format' => 'int64'],
            'fullName' => ['description' => 'Computed full name', 'type' => 'string', 'readOnly' => true],
            // Class-typed @property-read relation -> $ref to the relation schema.
            'club' => $this->ref('MemberLikeClub', $version, 'Home club'),
            // ClassName[] relation -> items.$ref to the relation schema.
            'clubs' => [
                'description' => 'Clubs the member belongs to',
                'type' => 'array',
                'items' => ['$ref' => self::REF . 'MemberLikeClub'],
                'readOnly' => true,
            ],
            // @property-write -> writeOnly, never readOnly.
            'password' => ['description' => 'Setter-only password', 'type' => 'string', 'writeOnly' => true],
        ], $full['properties']);
    }

    #[DataProvider('versions')]
    public function testAliasComponentPromotedWithRequiredExcludingOptionalKey(string $version): void
    {
        $schemas = $this->components($version)['schemas'];

        // The @phpstan-type array shape is promoted to its own named component; "line2"
        // is declared optional ("line2?:") so it is excluded from required.
        $this->assertEquals([
            'type' => 'object',
            'required' => ['line1'],
            'properties' => [
                'line1' => ['type' => 'string'],
                'line2' => ['type' => 'string'],
            ],
        ], $schemas['AddressShape']);
    }

    public function testComponentsResponseNestedOnSameClassSurvivesExpansion(): void
    {
        // Regression coverage for 3da820ae ("expand schema projections nested under
        // Components"): the class also carries a @OA\Components(@OA\Response(...)) block,
        // which pulls all three sibling @OA\Schema tags in as nested children of that
        // Components annotation (Components::$_nested maps Schema too). Without the fix,
        // those schemas would be skipped by ExpandSchemaProperties/ExpandTypeAliases
        // entirely (not "root"), and the response itself must still land in the final spec.
        $components = $this->components(OA\OpenApi::VERSION_3_1_0);

        $this->assertEquals(['description' => 'Member not found'], $components['responses']['MemberLikeNotFound']);
        $this->assertArrayHasKey('CreateMemberLike', $components['schemas']);
    }
}
