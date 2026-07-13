# Expanded Syntax Proposal

## Status

This document proposes source-level shorthand for swagger-php. None of the new
arguments or helper annotations described here are implemented yet.

The proposal has three goals:

1. Reuse PHP, PHPDoc, PHPStan, and Psalm type information instead of repeating
   it in OpenAPI annotations.
2. Make projections of a PHP model concise through `base`, `pick`, and `omit`.
3. Make common JSON request and response declarations concise without removing
   the existing fully explicit OpenAPI syntax.

All features must have equivalent support in both annotation syntaxes:

- PHP 8 attributes such as `#[OA\Schema(...)]`.
- Docblock annotations such as `@OA\Schema(...)`.

PHPDoc type tags are a shared source of type information for both syntaxes.
In this document, **attribute** means PHP 8 attribute, **docblock annotation**
means an `@OA\...` annotation, and **PHPDoc** means tags such as `@property` or
`@phpstan-type`.

Existing explicit syntax remains supported and always provides the escape hatch
for OpenAPI features that cannot be inferred.

## Schema projections

### Basic model

A schema may select properties from the PHP class on which it is declared:

- `pick`: include named properties from the class property pool.
- `omit`: exclude named properties inherited from `base` or selected locally.
- `base`: derive from another named OpenAPI schema.

PHP 8 attributes:

```php
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateUser',
    required: ['firstName', 'lastName'],
    pick: [
        'firstName',
        'lastName',
        'email',
        'cellPhone',
    ],
)]
#[OA\Schema(
    schema: 'User',
    base: 'CreateUser',
    pick: [
        'user_ID',
        'userName',
        'isActiveMember',
    ],
)]
#[OA\Schema(
    schema: 'UserV2',
    base: 'User',
    pick: ['uuid'],
    omit: ['userName'],
)]
class Member
{
}
```

Docblock annotations:

```php
/**
 * @OA\Schema(
 *     schema="CreateUser",
 *     required={"firstName", "lastName"},
 *     pick={"firstName", "lastName", "email", "cellPhone"}
 * )
 * @OA\Schema(
 *     schema="User",
 *     base="CreateUser",
 *     pick={"user_ID", "userName", "isActiveMember"}
 * )
 * @OA\Schema(
 *     schema="UserV2",
 *     base="User",
 *     pick={"uuid"},
 *     omit={"userName"}
 * )
 */
class Member
{
}
```

### OpenAPI output strategy

`base` is source-level schema composition. Its OpenAPI output depends on whether
the derived schema subtracts anything:

- `base` with only `pick` emits `allOf`, preserving normal OpenAPI composition.
- `base` with `omit` is materialized as a standalone schema because OpenAPI
  cannot subtract properties from an `allOf` reference.

For example:

```php
#[OA\Schema(schema: 'User', base: 'CreateUser', pick: ['user_ID'])]
```

conceptually emits:

```yaml
User:
  allOf:
    - $ref: '#/components/schemas/CreateUser'
    - type: object
      properties:
        user_ID:
          type: integer
```

But:

```php
#[OA\Schema(
    schema: 'UserV2',
    base: 'User',
    pick: ['uuid'],
    omit: ['userName'],
)]
```

is flattened before serialization:

```yaml
UserV2:
  type: object
  properties:
    # Materialized User properties except userName.
    uuid:
      type: string
```

When flattening, swagger-php must also:

- remove omitted properties from `required`;
- preserve inherited descriptions and constraints;
- apply local explicit properties after inherited properties;
- reject or diagnose missing bases and inheritance cycles;
- avoid mutating the base component.

### Property source pool

The selectable property pool includes, in precedence order:

1. Real PHP properties and promoted properties.
2. Class-level `@property`, `@property-read`, and `@property-write` PHPDoc tags.
3. Explicit `OA\Property` annotations declared inside the schema.

PHPDoc example:

```php
/**
 * @property int $user_ID Internal RecHub user ID
 * @property string|null $firstName First name
 * @property string|null $lastName Last name
 * @property-read bool $isActiveMember Active member
 * @property-read Member|null $parent Parent account
 * @property-read Member[] $children Child accounts
 */
class Member
{
}
```

The trailing PHPDoc text becomes the OpenAPI property description when an
explicit description is not supplied.

Access tags set OpenAPI access metadata:

- `@property-read` implies `readOnly: true`.
- `@property-write` implies `writeOnly: true`.
- `@property` sets neither flag.

Native types and PHPDoc types continue to use the configured
`TypeResolverInterface`. PHPDoc takes precedence where it is more specific.

`@method` tags are not properties by default. A generic PHP class can expose a
method without exposing a magic property. Framework integrations may opt into
getter/setter conventions, but portable source should declare the corresponding
`@property-read` or `@property-write` tag explicitly.

### Explicit property overrides

Explicit properties override picked properties with the same output name. This
keeps inference useful without blocking formats, enums, examples, or projected
values.

PHP 8 attributes:

```php
#[OA\Schema(
    schema: 'CreateUser',
    pick: ['member_directory', 'pictureUrl'],
    properties: [
        new OA\Property(
            property: 'member_directory',
            type: 'string',
            enum: ['OPT_IN', 'OPT_OUT'],
        ),
        new OA\Property(
            property: 'pictureUrl',
            type: 'string',
            format: 'url',
            nullable: true,
        ),
    ],
)]
```

Docblock annotations:

```php
/**
 * @OA\Schema(
 *     schema="CreateUser",
 *     pick={"member_directory", "pictureUrl"},
 *     @OA\Property(
 *         property="member_directory",
 *         type="string",
 *         enum={"OPT_IN", "OPT_OUT"}
 *     ),
 *     @OA\Property(
 *         property="pictureUrl",
 *         type="string",
 *         format="url",
 *         nullable=true
 *     )
 * )
 */
```

### Property renaming

`rename` maps a PHP property name to its serialized OpenAPI name. It changes the
output property name, not the PHP type.

PHP 8 attributes:

```php
#[OA\Schema(
    schema: 'UserFull',
    base: 'User',
    pick: ['classifications'],
    rename: [
        'classifications' => 'personTypes',
    ],
)]
```

Docblock annotations:

```php
/**
 * @OA\Schema(
 *     schema="UserFull",
 *     base="User",
 *     pick={"classifications"},
 *     rename={"classifications": "personTypes"}
 * )
 */
```

Rules:

- `pick` and `omit` use source property names.
- `required` uses final output names.
- explicit properties match final output names and override renamed picks.
- duplicate final names are diagnosed unless an explicit property resolves the
  collision.

### Canonical schemas and PHP class references

A PHP class can have multiple OpenAPI projections. One may be marked canonical
so ordinary PHP class references resolve predictably.

PHP 8 attributes:

```php
/**
 * @property-read Member|null $parent
 * @property-read Member[] $children
 */
#[OA\Schema(
    schema: 'User',
    canonical: true,
    pick: ['user_ID', 'firstName', 'lastName'],
)]
#[OA\Schema(
    schema: 'UserFull',
    base: 'User',
    pick: ['parent', 'children'],
)]
class Member
{
}
```

Docblock annotations:

```php
/**
 * @property-read Member|null $parent
 * @property-read Member[] $children
 *
 * @OA\Schema(
 *     schema="User",
 *     canonical=true,
 *     pick={"user_ID", "firstName", "lastName"}
 * )
 * @OA\Schema(
 *     schema="UserFull",
 *     base="User",
 *     pick={"parent", "children"}
 * )
 */
class Member
{
}
```

Both `parent` and `children.items` resolve to
`#/components/schemas/User`. The PHPDoc collection and nullability modifiers
are preserved.

Canonical rules:

- zero canonical schemas retains existing single-schema resolution behavior;
- exactly one canonical schema is the default ref target for the PHP class;
- more than one canonical schema on the same PHP class is an error;
- an explicit `ref` always wins.

For a local exception to the canonical mapping, `refs` remaps PHP types while
building one schema:

```php
#[OA\Schema(
    schema: 'FamilyWithFullChildren',
    pick: ['children'],
    refs: [
        Member::class => 'UserFull',
    ],
)]
```

Docblock equivalent:

```php
/**
 * @OA\Schema(
 *     schema="FamilyWithFullChildren",
 *     pick={"children"},
 *     refs={Member::class: "UserFull"}
 * )
 */
```

## PHPStan and Psalm type aliases

### Promotion to components

Class-level `@phpstan-type` and `@psalm-type` aliases are parsed as reusable
schema definitions. The aliases remain valid static-analysis declarations; no
OpenAPI syntax is placed inside the alias.

```php
/**
 * @phpstan-type InvoiceLine array{
 *     item: FinanceItem,
 *     price: numeric-string,
 *     quantity: positive-int,
 *     note?: string|null
 * }
 *
 * @psalm-type LoginResult = array{
 *     success: bool,
 *     token: string,
 *     user: Member
 * }
 */
class ApiTypes
{
}
```

Aliases may be referenced from `@var`, `@param`, `@return`, `@property`, and
other aliases. The existing type resolver maps shapes, lists, maps, unions,
intersections, nullability, integer ranges, and class refs into OpenAPI.

Aliases are auto-promoted by default so that writing and using an alias is the
zero-boilerplate path. `CleanUnusedComponents` may remove aliases that are not
reachable from the generated specification.

An explicit schema can bind to an alias to add OpenAPI-only metadata or choose a
different component name.

PHP 8 attributes:

```php
#[OA\Schema(
    schema: 'InvoiceLineResponse',
    typeAlias: 'InvoiceLine',
    description: 'A serialized invoice line',
)]
class ApiTypes
{
}
```

Docblock annotations:

```php
/**
 * @phpstan-type InvoiceLine array{item: FinanceItem, price: numeric-string}
 *
 * @OA\Schema(
 *     schema="InvoiceLineResponse",
 *     typeAlias="InvoiceLine",
 *     description="A serialized invoice line"
 * )
 */
class ApiTypes
{
}
```

Alias naming rules:

- the first unambiguous alias uses its declared name;
- colliding aliases are qualified as `OwnerClass.Alias`;
- an explicit `schema` plus `typeAlias` pins the public component name;
- duplicate pinned component names are errors;
- recursive aliases are supported only when they can be represented by refs;
- unresolved aliases produce diagnostics and do not silently become `object`.

PHPStan array-shape fields must have types. This is invalid PHPStan syntax for a
keyed shape:

```php
/** @phpstan-type UserFull array{user_ID, firstName} */
```

Use:

```php
/** @phpstan-type UserFull array{user_ID: int, firstName: string} */
```

When types already live on class `@property` tags, schema `pick` is the concise
alternative to repeating them in an alias.

## Operation shorthand

### JSON request bodies

`body` declares a required `application/json` request body by PHP class,
PHPStan/Psalm alias, or schema name.

PHP 8 attribute:

```php
#[OA\Post(
    path: '/user',
    body: MemberCreateForm::class,
)]
```

Docblock annotation:

```php
/**
 * @OA\Post(
 *     path="/user",
 *     body=MemberCreateForm::class
 * )
 */
```

The class form requires `MemberCreateForm` to have one schema or a schema marked
`canonical`. Otherwise, use the explicit schema name as `body="CreateUser"`.

The existing explicit `requestBody` remains available and takes precedence.

### Response maps

For common JSON operations, `responses` may be a status-keyed map. Values are
typed source refs or typed composition helpers:

PHP 8 attributes:

```php
#[OA\Post(
    path: '/user',
    body: MemberCreateForm::class,
    responses: [
        201 => Member::class,
        400 => new OA\OneOf(
            ValidationException::class,
            InvalidApiParameterException::class,
        ),
        403 => AccessDeniedException::class,
    ],
)]
```

Docblock annotations:

```php
/**
 * @OA\Post(
 *     path="/user",
 *     body=MemberCreateForm::class,
 *     responses={
 *         "201": Member::class,
 *         "400": @OA\OneOf(types={
 *             ValidationException::class,
 *             InvalidApiParameterException::class
 *         }),
 *         "403": AccessDeniedException::class
 *     }
 * )
 */
```

Response-map values mean:

- PHP class: JSON body referencing that class's canonical schema.
- PHPStan/Psalm alias or schema-name string: JSON body referencing that
  component.
- `OA\OneOf`: JSON body that must match exactly one listed schema.
- `OA\AnyOf`: JSON body that may match one or more listed schemas.
- `null`: response with no body.
- explicit `OA\Response`: full control over description, headers, links, and
  content.

The status code supplies the default reason-phrase description, such as
`Created`, `Bad Request`, or `Forbidden`. An explicit response description wins.

`OneOf` and `AnyOf` have equivalent attribute and docblock forms:

```php
new OA\OneOf(Foo::class, Bar::class)
new OA\AnyOf(Foo::class, Bar::class)
```

```php
@OA\OneOf(types={Foo::class, Bar::class})
@OA\AnyOf(types={Foo::class, Bar::class})
```

Their semantics follow JSON Schema/OpenAPI:

- `oneOf`: the payload validates against exactly one listed schema;
- `anyOf`: the payload validates against one or more listed schemas;
- `allOf`: the payload validates against every listed schema.

An operation cannot contain multiple separate responses with the same status
code. Variants for one status belong in `oneOf` or `anyOf`.

For array response bodies, use a PHPDoc list/array alias or a typed helper rather
than an untyped string convention:

```php
/** @phpstan-type UserList list<Member> */
```

```php
responses: [200 => 'UserList']
```

The existing list of explicit `OA\Response` annotations remains valid. The
normalization processor accepts either representation and emits the same
OpenAPI tree.

### Explicit response shorthand

`OA\Response` may also accept JSON schema shortcuts directly. This is useful
when a custom description, headers, or links are needed.

PHP 8 attributes:

```php
new OA\Response(
    response: 400,
    description: 'Invalid request',
    oneOf: [
        ValidationException::class,
        InvalidApiParameterException::class,
    ],
)
```

Docblock annotations:

```php
@OA\Response(
    response=400,
    description="Invalid request",
    oneOf={ValidationException::class, InvalidApiParameterException::class}
)
```

The proposed `schema`, `oneOf`, and `anyOf` arguments create
`application/json` content internally. Existing explicit `content` wins and is
required for alternate media types or advanced content negotiation.

### Operation defaults and inference

Repeated operation metadata may be declared at class level.

PHP 8 attributes:

```php
#[OA\OperationDefaults(
    tags: ['User'],
    operationIdPrefix: 'internalv1_user',
    security: [
        ['bearerAuth' => ['create users']],
        ['cookie' => ['create users']],
    ],
)]
class UserController
{
}
```

Docblock annotations:

```php
/**
 * @OA\OperationDefaults(
 *     tags={"User"},
 *     operationIdPrefix="internalv1_user",
 *     security={
 *         {"bearerAuth": {"create users"}},
 *         {"cookie": {"create users"}}
 *     }
 * )
 */
class UserController
{
}
```

Defaults fill only undefined operation fields. Method-level values always win.

The following inference is framework-neutral and safe:

- HTTP method comes from `OA\Get`, `OA\Post`, and related annotations.
- `{id}` in the declared path plus `int $id` on the PHP method creates a
  required integer path parameter.
- parameter nullability and collection types come from reflection/PHPDoc.
- status descriptions come from standard HTTP reason phrases.
- an operation ID may be constructed from an explicit prefix, action method,
  and HTTP method.

Path discovery from framework routes is not part of core inference. A framework
integration may provide it, but core swagger-php should not guess Yii, Symfony,
Laravel, or other routing conventions.

## Precedence and compatibility

The general precedence rule is:

1. Explicit nested OpenAPI annotation.
2. Explicit shorthand argument on the enclosing annotation.
3. Schema or operation defaults.
4. PHP reflection and PHPDoc inference.
5. OpenAPI/HTTP defaults.

Existing source continues to work without changes. New shorthand is normalized
into ordinary OpenAPI annotations before existing augmentation and validation
processors need the final structure.

Invalid or ambiguous shorthand must produce an actionable diagnostic. It must
not silently select a random schema, discard a property, or emit a weakened
`object` schema.

## End-to-end example

PHP 8 attributes:

```php
/**
 * @property int $user_ID Internal user ID
 * @property string|null $firstName First name
 * @property string|null $lastName Last name
 * @property-read bool $isActiveMember Active member
 * @property-read Member[] $children Child accounts
 */
#[OA\Schema(
    schema: 'CreateUser',
    required: ['firstName', 'lastName'],
    pick: ['firstName', 'lastName'],
)]
#[OA\Schema(
    schema: 'User',
    canonical: true,
    base: 'CreateUser',
    pick: ['user_ID', 'isActiveMember'],
)]
#[OA\Schema(
    schema: 'UserFull',
    base: 'User',
    pick: ['children'],
)]
class Member
{
}

#[OA\OperationDefaults(tags: ['User'], operationIdPrefix: 'internalv1_user')]
class UserController
{
    #[OA\Post(
        path: '/user',
        body: 'CreateUser',
        responses: [
            201 => Member::class,
            400 => new OA\OneOf(
                ValidationException::class,
                InvalidApiParameterException::class,
            ),
            403 => AccessDeniedException::class,
        ],
    )]
    public function actionCreate(): void
    {
    }
}
```

Equivalent docblock annotations:

```php
/**
 * @property int $user_ID Internal user ID
 * @property string|null $firstName First name
 * @property string|null $lastName Last name
 * @property-read bool $isActiveMember Active member
 * @property-read Member[] $children Child accounts
 *
 * @OA\Schema(
 *     schema="CreateUser",
 *     required={"firstName", "lastName"},
 *     pick={"firstName", "lastName"}
 * )
 * @OA\Schema(
 *     schema="User",
 *     canonical=true,
 *     base="CreateUser",
 *     pick={"user_ID", "isActiveMember"}
 * )
 * @OA\Schema(
 *     schema="UserFull",
 *     base="User",
 *     pick={"children"}
 * )
 */
class Member
{
}

/**
 * @OA\OperationDefaults(
 *     tags={"User"},
 *     operationIdPrefix="internalv1_user"
 * )
 */
class UserController
{
    /**
     * @OA\Post(
     *     path="/user",
     *     body="CreateUser",
     *     responses={
     *         "201": Member::class,
     *         "400": @OA\OneOf(types={
     *             ValidationException::class,
     *             InvalidApiParameterException::class
     *         }),
     *         "403": AccessDeniedException::class
     *     }
     * )
     */
    public function actionCreate(): void
    {
    }
}
```
