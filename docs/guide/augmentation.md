# Augmentation

One benefit of using `swagger-php` is that the library will use a number of strategies to augment your
annotations.

This includes things like:

* the name of properties
* the description of elements
* the data type of properties

Based on the context of the annotation `swagger-php` will look at (in order of priority):

* the OpenApi annotation itself
* the docblock (if any)
* PHP native type hints (if any)

**Example**

<codeblock id="context-awareness">
  <template v-slot:at>

<<< @/snippets/guide/augmentation/context_awareness_at.php

  </template>
  <template v-slot:an>

<<< @/snippets/guide/augmentation/context_awareness_an.php

  </template>
</codeblock>

**Results in**
```yaml
openapi: 3.0.0
components:
  schemas:
    Product:
      properties:
        name:
          description: "The product name"
          type: string
      type: object
```

**As if you'd written**

<codeblock id="explicit-context">
  <template v-slot:at>

<<< @/snippets/guide/augmentation/explicit_context_at.php

  </template>
  <template v-slot:an>

<<< @/snippets/guide/augmentation/explicit_context_an.php

  </template>
</codeblock>

## Data types

In particular, the following schema elements may be augmented:

* `nullable`
* `items`
* `ref`
* `type`
* `format`
* `minimum` / `maximum`
* `const`
* `enum`

Data types that are not recognized and cannot be resolved to a class-like name (`class`, `interface`, `enum`, etc.) will be ignored.

Mots of these schema elements will be resolved in isolation. For example, `nullable` may be enforced by the OpenApi annotation
while the actual data type may be resolved from the docblock (or type-hint).

### PHPStan type aliases

Class-level `@phpstan-type` and `@psalm-type` aliases are a source of type information too. When the `TypeInfoTypeResolver`
is active (the default), a declared alias is promoted to its own named schema under `#/components/schemas`, and every
place the alias is used - `@var`, `@param`, `@return`, `@property` and other aliases - resolves to a `$ref` of that
component instead of an inline shape.

<codeblock id="type-alias-promotion">
  <template v-slot:at>

<<< @/snippets/guide/augmentation/type_alias_promotion_at.php

  </template>
  <template v-slot:an>

<<< @/snippets/guide/augmentation/type_alias_promotion_an.php

  </template>
</codeblock>

`InvoiceLine` becomes its own component (named after the alias), `Invoice.lines` becomes an array of `$ref`s to it, and
the `FinanceItem` class reference inside the shape resolves to a `$ref` the same way a typed property would.

`@phpstan-import-type`/`@psalm-import-type` reuse the imported alias's promoted component rather than creating a
duplicate. Importing under an `as` alias only changes the local name used inside the importing class; the OpenAPI
component is still the one shared component:

```php
/**
 * @phpstan-import-type OrderLine from Order as ImportedLine
 */
#[OA\Schema]
class OrderImporter
{
    /** @var ImportedLine[] */
    #[OA\Property]
    public array $importedLines;
}
```

`importedLines` still resolves to `#/components/schemas/OrderLine` - no separate `ImportedLine` schema is created.

An explicit `OA\Schema` can bind to an alias declared on the same class via `typeAlias`, to pin the component name or
add OpenAPI-only metadata (`description`, `required`, etc.) that isn't part of the PHP type:

<codeblock id="type-alias-binding">
  <template v-slot:at>

<<< @/snippets/guide/augmentation/type_alias_binding_at.php

  </template>
  <template v-slot:an>

<<< @/snippets/guide/augmentation/type_alias_binding_an.php

  </template>
</codeblock>

Because the schema is bound, there is a single `InvoiceLineResponse` component carrying both the alias body and the
`description` - no separate, unreferenced `InvoiceLine` schema is generated.

Alias naming rules:

- the first unambiguous alias uses its declared name as the component name;
- an alias name that collides with another class's alias of the same name is qualified as `OwnerClass.Alias` for every
  class after the first;
- unresolved aliases produce a warning and are skipped rather than silently becoming `object`.

Aliases that reference a `@template` type parameter (i.e. still generic at the point they're declared) are not
promoted; they are out of scope for this feature.

Promoted aliases are ordinary components, so the `cleanUnusedComponents` config setting (see
[`CleanUnusedComponents`](../reference/processors.md#cleanunusedcomponents)) removes ones that end up unreferenced by
the generated spec just like any other unused schema.

If you don't want alias promotion at all, remove the processor from the pipeline:

```php
$generator->withProcessorPipeline(
    fn ($pipeline) => $pipeline->remove(\OpenApi\Processors\ExpandTypeAliases::class)
);
```

### Schema projections

An `OA\Schema` can select and reshape properties from the PHP class it's declared on instead of repeating every
property by hand:

- `pick` selects named properties from the class's property pool.
- `omit` excludes properties, whether picked locally or inherited via `base`.
- `base` derives a schema from another named schema declared on the same class.
- `rename` maps a selected property's source name to its serialized output name.

The property pool for a schema is built, in precedence order, from real (including promoted) PHP properties, then
class-level `@property`/`@property-read`/`@property-write` docblock tags (`@property-read` implies `readOnly: true`,
`@property-write` implies `writeOnly: true`, and the tag's trailing text becomes the `description` when one isn't
already set), and finally any `OA\Property` declared explicitly inside the schema - which always wins and is merged
onto the picked/inherited property rather than replacing it outright, so a picked property keeps its inferred type
even when only its `format` or `enum` is overridden explicitly.

The `@property`/`@property-read`/`@property-write` docblock tags are collected from the full class hierarchy, so a
subclass can `pick` a virtual property declared only on an ancestor's docblock (for example an `id` a shared
base class documents). When the same property name is declared at more than one level, the most-derived class's tag
wins.

<codeblock id="schema-projection-member">
  <template v-slot:at>

<<< @/snippets/guide/augmentation/schema_projection_member_at.php

  </template>
  <template v-slot:an>

<<< @/snippets/guide/augmentation/schema_projection_member_an.php

  </template>
</codeblock>

`Member` derives from `CreateMember` with nothing omitted, so it's emitted as `allOf` composition: a `$ref` to
`CreateMember` plus a payload schema with just the added/renamed properties. `MemberSummary` derives from `Member`
and omits properties, so OpenAPI can't express that as `allOf` (composition can't subtract) - it's materialized as a
standalone, flattened schema instead, with omitted properties also removed from `required`.

Projections also work for schemas nested under a class-level `OA\Components` block: stacking a bare `#[OA\Components]`
(or `@OA\Components`) attribute alongside the `OA\Schema` attributes on one class groups those schemas as components
the same way a top-level `#[OA\Components(schemas: [...])]` would, and `pick`/`omit`/`base`/`typeAlias` all work the
same way inside it.

<codeblock id="schema-projection-components">
  <template v-slot:at>

<<< @/snippets/guide/augmentation/schema_projection_components_at.php

  </template>
  <template v-slot:an>

<<< @/snippets/guide/augmentation/schema_projection_components_an.php

  </template>
</codeblock>

## Summary and description

`summary` and `description` of certain annotation types (`OA\Operation`, `OA\Property`, `OA\Parameter` and `OA\Schema`) may be augmented
by extracting the summary and description of the docblock (if any).

## References

OpenApi specs support references to allow reuse of elements and reduce duplication. One of the most common use cases is
to set the `type` or `ref` property of a schema to a class name. The only requirement is that the class name must be
annotated as a `OA\Schema` itself.

## Tags

Tags are an OpenApi concept and may be added to your spec via th `Tag` annotation. For convenience, however, it is also
possible to create tags by just adding them to your HTTP operations (`Get`, `Post`, etc.).
All tags used that are not explicitly defined with a `Tag` annotation will be added to the spec.
