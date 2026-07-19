<?php

namespace Openapi\Snippets\Augmentation\SchemaProjectionMember;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="CreateMember",
 *     required={"email", "password"},
 *     pick={"email", "password"}
 * )
 * @OA\Schema(
 *     schema="Member",
 *     base="CreateMember",
 *     pick={"id", "isActiveMember"},
 *     rename={"isActiveMember": "active"},
 *     @OA\Property(
 *         property="id",
 *         format="uuid"
 *     )
 * )
 * @OA\Schema(
 *     schema="MemberSummary",
 *     base="Member",
 *     omit={"password", "active"}
 * )
 *
 * @property-read bool $isActiveMember Whether the membership is active
 * @property-write string $password Write-only password
 */
class Member
{
    /**
     * @OA\Property
     */
    public int $id;

    /**
     * @OA\Property
     */
    public string $email;
}
