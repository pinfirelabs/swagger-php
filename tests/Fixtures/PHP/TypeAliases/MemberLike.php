<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi\Tests\Fixtures\PHP\TypeAliases;

/**
 * @phpstan-type AddressShape array{line1: string, line2?: string}
 *
 * @property int          $id        Member id
 * @property string       $firstName First name
 * @property string       $lastName  Last name
 * @property bool         $isActive  Active flag
 * @property AddressShape $address   Mailing address
 * @property-read string           $fullName Computed full name
 * @property-read MemberLikeClub   $club     Home club
 * @property-read MemberLikeClub[] $clubs    Clubs the member belongs to
 * @property-write string $password Setter-only password
 *
 * @OA\Components(
 *     @OA\Response(
 *         response="MemberLikeNotFound",
 *         description="Member not found"
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="CreateMemberLike",
 *     pick={"firstName", "lastName", "isActive", "address"},
 *     required={"firstName", "lastName"}
 * )
 * @OA\Schema(
 *     schema="MemberLike",
 *     base="CreateMemberLike",
 *     pick={"id", "fullName"},
 *     @OA\Property(property="id", format="int64")
 * )
 * @OA\Schema(
 *     schema="MemberLikeFull",
 *     base="MemberLike",
 *     omit={"firstName"},
 *     pick={"club", "clubs", "password"}
 * )
 */
class MemberLike
{
}
