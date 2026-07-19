<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Type;

use OpenApi\Tests\OpenApiTestCase;
use OpenApi\Type\TypeAliases;
use PHPUnit\Framework\Attributes\DataProvider;

final class TypeAliasesTest extends OpenApiTestCase
{
    private const NS = 'OpenApi\\Tests\\Fixtures\\PHP\\TypeAliases\\';

    /**
     * @return array<string, array{owner: class-string, alias: string, type: ?string, templated: bool}>
     */
    private function aliases(string $class): array
    {
        return (new TypeAliases())->forClass(new \ReflectionClass(self::NS . $class));
    }

    public function testCollectsDeclaredAliases(): void
    {
        $aliases = $this->aliases('Order');

        $this->assertSame(['OrderLine', 'Uuid', 'LineList'], array_keys($aliases));
        $this->assertSame(self::NS . 'Order', $aliases['OrderLine']['owner']);
        $this->assertSame('OrderLine', $aliases['OrderLine']['alias']);
        $this->assertStringContainsString('numeric-string', (string) $aliases['OrderLine']['type']);
        $this->assertSame('string', $aliases['Uuid']['type']);
        $this->assertSame('OrderLine[]', $aliases['LineList']['type']);
        $this->assertFalse($aliases['OrderLine']['templated']);
    }

    public function testPsalmEqualsForm(): void
    {
        $aliases = $this->aliases('PsalmAliases');

        $this->assertArrayHasKey('Money', $aliases);
        $this->assertSame('array{amount: int, currency: string}', $aliases['Money']['type']);
    }

    public function testImportAsRename(): void
    {
        $aliases = $this->aliases('OrderImporter');

        // Imported under a new local name but resolves to the original owner/alias so
        // usages collapse onto the single promoted component.
        $this->assertSame(['ImportedLine'], array_keys($aliases));
        $this->assertSame(self::NS . 'Order', $aliases['ImportedLine']['owner']);
        $this->assertSame('OrderLine', $aliases['ImportedLine']['alias']);
    }

    public function testChainedImport(): void
    {
        $aliases = $this->aliases('ChainTip');

        $this->assertSame(['Twig'], array_keys($aliases));
        $this->assertSame(self::NS . 'ChainRoot', $aliases['Twig']['owner']);
        $this->assertSame('Leaf', $aliases['Twig']['alias']);
        $this->assertSame('array{id: int}', $aliases['Twig']['type']);
    }

    public function testBrokenImportSkipped(): void
    {
        $this->assertSame([], $this->aliases('BrokenImporter'));
    }

    public function testTemplatedAliasFlagged(): void
    {
        $aliases = $this->aliases('Templated');

        $this->assertTrue($aliases['Wrapper']['templated']);
        $this->assertFalse($aliases['Plain']['templated']);
    }

    /**
     * @param array{0: string, 1: string}|null $parsed
     */
    #[DataProvider('markerCases')]
    public function testMarkerRoundTrip(string $owner, string $alias, string $marker, ?array $parsed): void
    {
        $this->assertSame($marker, TypeAliases::marker($owner, $alias));
        $this->assertSame($parsed, TypeAliases::parseMarker($marker));
    }

    public static function markerCases(): iterable
    {
        yield 'leading slash trimmed' => ['\\Foo\\Bar', 'Line', 'Foo\\Bar@Line', ['Foo\\Bar', 'Line']];
        yield 'global class' => ['Order', 'Uuid', 'Order@Uuid', ['Order', 'Uuid']];
    }

    public function testParseMarkerRejectsPlainType(): void
    {
        $this->assertNull(TypeAliases::parseMarker('Foo\\Bar'));
        $this->assertNull(TypeAliases::parseMarker('string'));
    }
}
