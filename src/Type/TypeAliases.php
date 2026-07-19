<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Type;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasImportTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Symfony\Component\TypeInfo\TypeContext\TypeContextFactory;

/**
 * Collects <code>@phpstan-type</code>/<code>@psalm-type</code> aliases (including
 * <code>@phpstan-import-type</code>/<code>@psalm-import-type</code>) declared in a class docblock.
 *
 * Aliases are keyed by their local name in the given class and carry the owning class of
 * the underlying declaration, so a single alias definition shared through imports resolves
 * to one promoted component.
 */
final class TypeAliases
{
    private Lexer $phpDocLexer;

    private PhpDocParser $phpDocParser;

    private TypeContextFactory $bareTypeContextFactory;

    /** @var array<string, array<string, array{owner: class-string, alias: string, type: ?string, templated: bool}>> */
    private array $cache = [];

    public function __construct()
    {
        $config = new ParserConfig([]);
        $constExprParser = new ConstExprParser($config);
        $this->phpDocLexer = new Lexer($config);
        $this->phpDocParser = new PhpDocParser(
            $config,
            new TypeParser($config, $constExprParser),
            $constExprParser,
        );
        $this->bareTypeContextFactory = new TypeContextFactory();
    }

    public static function marker(string $ownerFqcn, string $alias): string
    {
        return ltrim($ownerFqcn, '\\') . '@' . $alias;
    }

    /**
     * @return array{0: string, 1: string}|null the owner FQCN and alias name, or null when the string is not a marker
     */
    public static function parseMarker(string $type): ?array
    {
        if (!str_contains($type, '@')) {
            return null;
        }
        [$owner, $alias] = explode('@', $type, 2);
        if ('' === $owner || '' === $alias || str_contains($alias, '@')) {
            return null;
        }

        return [$owner, $alias];
    }

    /**
     * @return array<string, array{owner: class-string, alias: string, type: ?string, templated: bool}> keyed by local alias name
     */
    public function forClass(\ReflectionClass $class): array
    {
        return $this->collect($class, []);
    }

    /**
     * @param  array<string, bool>                                                                      $visited
     * @return array<string, array{owner: class-string, alias: string, type: ?string, templated: bool}>
     */
    private function collect(\ReflectionClass $class, array $visited): array
    {
        $name = $class->getName();
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }
        if (isset($visited[$name])) {
            return [];
        }
        $visited[$name] = true;

        $docComment = $class->getDocComment();
        if (!$docComment) {
            return $this->cache[$name] = [];
        }

        $node = $this->parse($docComment);
        $templateNames = $this->templateNames($node);

        $result = [];

        foreach (['@phpstan-type', '@psalm-type'] as $tagName) {
            foreach ($node->getTagsByName($tagName) as $tag) {
                if (!$tag->value instanceof TypeAliasTagValueNode) {
                    continue;
                }
                $type = (string) $tag->value->type;
                $result[$tag->value->alias] = [
                    'owner' => ltrim($name, '\\'),
                    'alias' => $tag->value->alias,
                    'type' => $type,
                    'templated' => $this->referencesTemplate($type, $templateNames),
                ];
            }
        }

        foreach (['@phpstan-import-type', '@psalm-import-type'] as $tagName) {
            foreach ($node->getTagsByName($tagName) as $tag) {
                if (!$tag->value instanceof TypeAliasImportTagValueNode) {
                    continue;
                }
                $localName = $tag->value->importedAs ?? $tag->value->importedAlias;
                $fromFqcn = $this->resolveImportedFrom((string) $tag->value->importedFrom, $class);
                if ($fromFqcn === null) {
                    continue;
                }
                try {
                    $fromClass = new \ReflectionClass($fromFqcn);
                } catch (\Throwable) {
                    continue;
                }
                $imported = $this->collect($fromClass, $visited)[$tag->value->importedAlias] ?? null;
                if ($imported === null) {
                    continue;
                }
                $result[$localName] = $imported;
            }
        }

        return $this->cache[$name] = $result;
    }

    private function parse(string $docComment): PhpDocNode
    {
        $tokens = new TokenIterator($this->phpDocLexer->tokenize($docComment));

        return $this->phpDocParser->parse($tokens);
    }

    /**
     * @return array<string, bool>
     */
    private function templateNames(PhpDocNode $node): array
    {
        $names = [];
        foreach (['@template', '@phpstan-template', '@psalm-template'] as $tagName) {
            foreach ($node->getTagsByName($tagName) as $tag) {
                if ($tag->value instanceof TemplateTagValueNode) {
                    $names[$tag->value->name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * @param array<string, bool> $templateNames
     */
    private function referencesTemplate(string $type, array $templateNames): bool
    {
        foreach (array_keys($templateNames) as $templateName) {
            if (preg_match('/\b' . preg_quote($templateName, '/') . '\b/', $type)) {
                return true;
            }
        }

        return false;
    }

    private function resolveImportedFrom(string $importedFrom, \ReflectionClass $class): ?string
    {
        try {
            $fqcn = $this->bareTypeContextFactory->createFromClassName($class->getName())->normalize(ltrim($importedFrom, '\\'));
        } catch (\Throwable) {
            return null;
        }

        if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn)) {
            return $fqcn;
        }

        return null;
    }
}
