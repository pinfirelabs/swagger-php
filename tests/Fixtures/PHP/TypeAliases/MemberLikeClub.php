<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Fixtures\PHP\TypeAliases;

use OpenApi\Attributes as OAT;

/**
 * Relation target referenced by MemberLike's computed getters.
 */
#[OAT\Schema]
class MemberLikeClub
{
    #[OAT\Property]
    public int $id;

    #[OAT\Property]
    public string $name;
}
