# Expanded Syntax Implementation Plan

## Objective

Implement the syntax described in `EXPANDED_SYNTAX.md` while preserving
swagger-php's existing annotation lifecycle and backward compatibility.

The implementation must support equivalent behavior from:

- PHP 8 attributes;
- docblock `@OA\...` annotations;
- PHPDoc, PHPStan, and Psalm type declarations used by either annotation syntax.

The final OpenAPI tree must contain ordinary schema, property, request body,
response, and media type annotations. Shorthand is source syntax only and must
not leak into serialized OpenAPI.

## Design principles

1. **Normalize early.** Convert shorthand into existing annotation objects
   before processors that expect final OpenAPI structure.
2. **Share resolution.** Attributes and docblocks feed the same metadata and
   processor paths after analysis.
3. **Prefer explicit declarations.** Explicit properties, refs, request bodies,
   responses, and content override inferred shorthand.
4. **Preserve type safety.** Attribute constructors expose typed named
   arguments, response maps document precise PHPStan unions, and PHP class refs
   are preferred over schema-name strings.
5. **Diagnose ambiguity.** Missing fields, duplicate canonical schemas,
   unresolved aliases, response collisions, and base cycles are errors or clear
   warnings, never arbitrary choices.
6. **Do not change OpenAPI semantics.** `oneOf`, `anyOf`, `allOf`, required
   properties, nullability, and refs retain their specification meanings.

## Proposed public API

### Schema additions

Add to `OpenApi\Annotations\Schema` and `OpenApi\Attributes\Schema`:

```php
/** @var string|class-string|object */
public $base;

/** @var list<string> */
public $pick;

/** @var list<string> */
public $omit;

/** @var array<string,string> */
public $rename;

/** @var bool */
public $canonical;

/** @var array<class-string,string|class-string|object> */
public $refs;

/** @var string */
public $typeAlias;
```

Use `Undefined::UNDEFINED` for annotation defaults and nullable constructor
arguments for attributes, following existing conventions.

Add corresponding entries to `$_types` where Doctrine validation can enforce
primitive/list values. These fields are internal source metadata and must be
removed or ignored during OpenAPI serialization after normalization.

### Response additions

Add optional JSON shorthand to `Annotations\Response` and
`Attributes\Response`:

```php
string|object|null $schema = null;
?array $oneOf = null;
?array $anyOf = null;
```

These are mutually exclusive with each other. Existing explicit `content`
takes precedence and produces a diagnostic when combined with shorthand.

### Composition helpers

Add source-only helper annotations:

- `OpenApi\Annotations\OneOf`
- `OpenApi\Attributes\OneOf`
- `OpenApi\Annotations\AnyOf`
- `OpenApi\Attributes\AnyOf`

Attribute usage is variadic and typed:

```php
new OA\OneOf(Foo::class, Bar::class)
new OA\AnyOf(Foo::class, Bar::class)
```

Docblock usage exposes a `types` list:

```php
@OA\OneOf(types={Foo::class, Bar::class})
@OA\AnyOf(types={Foo::class, Bar::class})
```

Helpers are valid only as shorthand response-map values. They normalize to
ordinary `OA\Schema(oneOf=...)` or `OA\Schema(anyOf=...)` objects and are never
serialized directly.

### Operation additions

Add to each HTTP operation attribute constructor through `OperationTrait`, and
to the shared operation annotation model:

```php
string|object|null $body = null;

/**
 * @var list<Response>|array<int|string,
 *     class-string|object|string|OneOf|AnyOf|Response|null>
 */
public $responses;
```

The existing list of nested `Response` annotations remains accepted. A
normalizer distinguishes a list of `Response` objects from a status-keyed map.

### Operation defaults

Add repeatable-source support for:

- `OpenApi\Annotations\OperationDefaults`
- `OpenApi\Attributes\OperationDefaults`

Initial fields:

```php
?array $tags = null;
?array $parameters = null;
?array $security = null;
?string $operationIdPrefix = null;
```

Limit its parent context to classes. Method-level operation fields override all
defaults. More fields can be added later without coupling the first release to
framework route discovery.

## Internal metadata model

### Virtual properties

Extend analysis metadata so each reflected class can expose a normalized list
of virtual properties discovered from:

- `@property`;
- `@property-read`;
- `@property-write`.

Each entry needs:

```text
source name
resolved PHPDoc type node
raw type text
description
read-only/write-only mode
declaring class/context
```

Do not synthesize properties from `@method` by default. Record method tags only
if needed for future method-return inference or an opt-in framework convention.

Real reflected properties and virtual properties should present a shared input
to schema-property construction. Avoid fabricating `ReflectionProperty`
instances; introduce a small source metadata value object consumed by the type
resolver/property factory.

### Type aliases

Collect class-level `@phpstan-type`, `@phpstan-import-type`, `@psalm-type`, and
the Psalm import equivalent where supported by `phpstan/phpdoc-parser`.

Each alias record needs:

```text
alias name
owner class/context
parsed type AST
import source and optional renamed alias
explicit public schema name, if bound
dependency aliases/classes
```

Use one registry per `Analysis`, not global state. The registry must support
forward references, imports, recursion detection, and deterministic collision
naming.

### Canonical schema registry

Build a registry from PHP source class to schema annotation:

```text
class-string => canonical schema component
```

Validation rules:

- at most one canonical schema per class;
- canonical schemas must have a component name;
- explicit refs bypass canonical lookup;
- a schema-local `refs` entry overrides canonical lookup only within that
  schema's generated property tree.

Integrate this registry with `Analysis::getAnnotationForSource()` or add a
purpose-built lookup method rather than relying on annotation discovery order.

## Processor pipeline

The exact class names may change during implementation, but responsibilities and
ordering should remain explicit.

### 1. Discover PHPDoc metadata during analysis

Extend the reflection analysis path to parse virtual-property and alias tags
once per docblock. Reuse the existing `PHPStan\PhpDocParser` configuration used
by `OpenApi\Type\TypeResolver`.

Do not parse the same class docblock independently in multiple processors.
Attach the normalized results to `Context` or an analysis-scoped metadata
registry.

### 2. Promote type aliases

Add a processor before schema projection expansion that:

1. resolves imports and alias dependencies;
2. converts alias ASTs to `SchemaType` trees;
3. creates component `OA\Schema` annotations;
4. assigns deterministic names;
5. binds explicit `typeAlias` schemas;
6. registers alias refs for subsequent type resolution.

Refactor `TypeResolver` as needed so an already parsed type AST can be mapped
without converting it back to a string.

### 3. Build canonical ref mappings

After components have been merged and schema names augmented, validate and
register canonical schemas. This must occur before picked properties and
response class refs are resolved.

### 4. Expand schema projections

Add a processor after component merge and basic schema-name augmentation, but
before `AugmentProperties`/`AugmentRefs`, that:

1. validates `base`, `pick`, `omit`, and `rename` combinations;
2. builds generated `OA\Property` annotations from reflected/virtual sources;
3. applies type, description, and access metadata;
4. applies schema-local ref mappings;
5. merges explicit properties last;
6. emits `allOf` when no subtraction is required;
7. materializes a standalone schema when `omit` is used;
8. reconciles `required` after rename/omit;
9. marks generated contexts consistently;
10. clears source-only shorthand fields before serialization.

Base resolution must be topologically ordered. Detect direct and indirect
cycles and include the complete cycle in the diagnostic.

Use deep clones when materializing a base. No annotation object may be shared
between the base and derived schema if a later processor can mutate it.

### 5. Apply operation defaults

Before operation augmentation, merge enclosing `OperationDefaults` into each
operation only where the operation field is undefined.

If `operationIdPrefix` is present and `operationId` is missing, construct a
deterministic ID from:

```text
prefix + normalized action method name + HTTP method
```

The existing `OperationId` processor remains the final fallback.

### 6. Normalize request and response shorthand

Before `AugmentRequestBody`, `AugmentRefs`, and media-type merging:

- turn `body` into `RequestBody(required=true)` plus
  `JsonContent(ref=<resolved source>)`;
- turn each status-map entry into an `OA\Response`;
- assign a standard reason phrase if no description is provided;
- turn class/alias/schema refs into JSON content refs;
- turn `OneOf`/`AnyOf` helpers into JSON content containing composed schemas;
- preserve explicit response objects and nested explicit response lists;
- reject duplicate normalized status codes.

Do not use status-map inference when the operation already has an explicit
request body/response for the same target. Explicit annotations win, and
conflicting shorthand should produce a diagnostic rather than being silently
dropped.

### Suggested ordering

Relative to the current default pipeline, the new responsibilities should land
approximately as follows:

```text
DocBlockDescriptions
MergeIntoOpenApi
MergeIntoComponents
PromoteTypeAliases          (new)
RegisterCanonicalSchemas    (new)
ExpandClasses / Interfaces / Traits / Enums
AugmentSchemas
ExpandSchemaProjections     (new)
ApplyOperationDefaults      (new)
NormalizeOperationShorthand (new)
AugmentRequestBody
AugmentProperties
BuildPaths
AugmentParameters
AugmentRefs
AugmentItems
MergeJsonContent / MergeXmlContent
...
```

Prototype tests should confirm whether canonical registration must move after
`AugmentSchemas` to guarantee names. If so, split alias promotion into discovery
and final registration rather than relying on an unstable order.

## Type resolution changes

Extend the configured type resolver rather than creating a second mapping
implementation.

Required capabilities:

- resolve a real reflector as today;
- resolve a virtual-property metadata object;
- resolve a parsed PHPDoc type AST;
- resolve class types through canonical schema mappings;
- resolve aliases through the analysis alias registry;
- apply schema-local `refs` recursively to lists, maps, unions, intersections,
  and shapes;
- preserve OpenAPI 3.0 versus 3.1 nullability behavior;
- preserve explicit schema fields over inferred values.

The legacy resolver may either receive equivalent support or explicitly reject
expanded PHPDoc features with a documented diagnostic. Since
`TypeInfoTypeResolver` is the default, implement and test it first, then decide
the supported compatibility surface for `LegacyTypeResolver` before release.

## Attribute and docblock parity

Every public feature needs paired fixtures asserting identical serialized
OpenAPI for attributes and docblocks:

| Feature | Attribute | Docblock annotation |
| --- | --- | --- |
| Projection | `#[OA\Schema(pick: [...])]` | `@OA\Schema(pick={...})` |
| Base | `base: 'User'` | `base="User"` |
| Omit | `omit: [...]` | `omit={...}` |
| Rename | `rename: ['a' => 'b']` | `rename={"a": "b"}` |
| Canonical | `canonical: true` | `canonical=true` |
| Ref override | `refs: [Foo::class => 'X']` | `refs={Foo::class: "X"}` |
| Alias binding | `typeAlias: 'Shape'` | `typeAlias="Shape"` |
| Body | `body: Foo::class` | `body=Foo::class` |
| Response map | status-keyed PHP array | keyed annotation array |
| One-of | `new OA\OneOf(...)` | `@OA\OneOf(types={...})` |
| Any-of | `new OA\AnyOf(...)` | `@OA\AnyOf(types={...})` |
| Defaults | `#[OA\OperationDefaults(...)]` | `@OA\OperationDefaults(...)` |

The Doctrine annotation parser's support for numeric map keys and class
constants must be tested early. If numeric keys are not stable, document quoted
status keys as canonical docblock syntax. Do not ship an attribute-only feature.

## Validation and diagnostics

Add targeted diagnostics for:

- picked property not found;
- omitted property not present in the materialized base/local selection;
- rename source not selected;
- duplicate renamed output;
- missing base schema;
- schema base cycle;
- more than one canonical schema for a PHP class;
- canonical schema without a component name;
- unresolved schema-local type mapping;
- unresolved PHPStan/Psalm alias or import;
- alias cycle that cannot be represented as refs;
- duplicate promoted component name;
- response-map status duplicated by an explicit response;
- invalid response-map value;
- simultaneous `schema`, `oneOf`, `anyOf`, or explicit `content`;
- `OneOf`/`AnyOf` with fewer than two members;
- a response class with no resolvable canonical/schema component;
- path placeholder with no method parameter or explicit parameter annotation.

Diagnostics should include source file, class/method, schema/operation identity,
and the offending field/status/type.

## Test plan

### Unit tests: PHPDoc discovery

- `@property`, `@property-read`, and `@property-write` parsing.
- Nullable, union, intersection, list, map, and array-shape types.
- Trailing descriptions.
- Namespaced and imported class names.
- Duplicate virtual/real property precedence.
- `@method` is not promoted by default.

### Unit tests: type aliases

- PHPStan and Psalm alias syntax.
- Nested aliases and imported aliases.
- Optional shape keys and open shapes.
- Class refs inside aliases.
- Auto-promotion and explicit binding.
- Collision qualification.
- Recursive aliases and invalid cycles.
- Use from `@var`, `@param`, `@return`, and `@property`.

### Unit tests: projections

- Root `pick` schema.
- `base` plus `pick` produces `allOf`.
- `base` plus `omit` produces a flattened schema.
- Required-list reconciliation.
- Chained bases.
- Explicit property override.
- Rename before explicit override.
- Canonical refs for scalar, nullable, list, map, union, and shape members.
- Schema-local ref override.
- Read-only/write-only virtual properties.
- Missing property/base and cycle diagnostics.

### Unit tests: operations

- `body` normalization.
- Response class/schema/alias ref.
- `null` body.
- `OneOf` and `AnyOf` response bodies.
- Explicit response mixed with map shorthand.
- Default and explicit descriptions.
- Custom headers/content retains explicit behavior.
- Duplicate status diagnostics.
- Controller defaults and method override.
- Path-parameter reflection inference.

### Parity tests

Create paired fixtures for every feature, one using PHP 8 attributes and one
using docblock annotations. Generate OpenAPI 3.0 and 3.1 from each and compare
normalized JSON trees.

Use compact data providers, following the existing type-resolver tests. Include
end-to-end fixtures that combine:

- virtual properties;
- aliases;
- canonical refs;
- projection inheritance;
- typed request bodies;
- success and error response maps.

### Regression tests

- Existing explicit annotations serialize unchanged.
- Existing response lists remain accepted.
- Existing class-to-schema resolution is unchanged when `canonical` is absent.
- Existing explicit `allOf`/`oneOf`/`anyOf` are untouched.
- Existing custom processor pipelines can include or remove new processors.
- `CleanUnusedComponents` can prune auto-promoted aliases.

### Quality checks

Run, at minimum:

```text
composer lint
composer analyse
composer test
```

Run focused PHPUnit suites during implementation before the full suite.

## Delivery phases

### Phase 1: property projections

- Virtual-property discovery.
- `pick`, explicit overrides, descriptions, and access flags.
- Attribute/docblock parity.

This produces immediate value without schema inheritance or operation changes.

### Phase 2: base, omit, rename, and canonical refs

- `base` plus `pick` `allOf` output.
- `omit` materialization.
- `rename`.
- canonical and local ref mappings.

### Phase 3: PHPStan/Psalm aliases

- Registry, promotion, bindings, collisions, and imports.
- Reuse across properties, parameters, request bodies, and responses.

### Phase 4: typed operation shorthand

- `body`.
- response maps.
- typed `OneOf`/`AnyOf`.
- JSON shortcuts on explicit `Response`.

### Phase 5: operation defaults and inference

- class-level defaults;
- operation-ID prefix;
- reflected path parameters;
- documented integration points for framework route resolvers.

Each phase should be independently releasable behind backward-compatible,
opt-in source syntax.

## Documentation work

Before release:

- add reference documentation for every new annotation field/helper;
- add paired attribute/docblock cookbook examples;
- document projection output differences (`allOf` versus materialized omit);
- document canonical schema selection;
- document PHPStan/Psalm alias collision rules;
- document response-map value types and JSON defaults;
- add a migration example converting a verbose controller and model;
- call out that PHPDoc property tags describe the PHP/API source model and that
  explicit overrides remain necessary for serialization transformations.

## Decisions captured by this proposal

- Keep separate `pick` and `omit` fields rather than a `+`/`-` mini-language.
- Preserve `allOf` when a derived schema only adds properties.
- Materialize a new schema when any property is omitted.
- Treat `Member`-style PHP class names and `User`-style API component names via
  an explicit canonical schema.
- Keep property-name `rename` separate from PHP-type-to-schema mapping.
- Use typed `OneOf`/`AnyOf` helpers rather than implicit array semantics.
- Prefer typed class refs in response maps; allow strings for aliases and named
  schemas that do not have PHP classes.
- Default response shorthand to JSON while retaining explicit `content` for
  advanced cases.
- Support both PHP 8 attributes and docblock annotations for every feature.
- Use PHPDoc/PHPStan/Psalm as shared type sources rather than tying inference to
  either OpenAPI annotation syntax.

## Open implementation questions

Resolve these with prototypes before freezing the public API:

1. Whether `base` should accept only component names or also class refs plus a
   projection selector.
2. Whether auto-promotion of every type alias is always enabled or controlled by
   generator configuration, with enabled as the proposed default.
3. The exact analysis-scoped storage API for virtual properties and aliases.
4. Whether the legacy type resolver receives full alias support or a narrower
   compatibility layer.
5. Whether `OperationDefaults.parameters` uses component names, refs, or a typed
   helper.
6. Whether a response-map string always means a schema component or can refer to
   a reusable Response component; the initial recommendation is schema only,
   with explicit `OA\Response(ref: ...)` for response components.
7. Whether `refs` is the clearest public name for schema-local type mappings.
8. Whether standard HTTP reason phrases should be configurable.
