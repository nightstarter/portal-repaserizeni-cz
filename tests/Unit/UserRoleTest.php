<?php

namespace Tests\Unit;

use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_roles_are_restricted_to_their_organization(): void
    {
        self::assertTrue(UserRole::AK_ADMIN->belongsTo(OrganizationCode::AUTOKLUBY));
        self::assertFalse(UserRole::AK_ADMIN->belongsTo(OrganizationCode::POLAND));
        self::assertTrue(UserRole::PL_MANAGER->belongsTo(OrganizationCode::POLAND));
        self::assertFalse(UserRole::PL_OPERATOR->belongsTo(OrganizationCode::AUTOKLUBY));
    }
}
