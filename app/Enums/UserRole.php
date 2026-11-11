<?php

namespace App\Enums;

enum UserRole: string
{
    case AK_ADMIN = 'AK_ADMIN';
    case AK_OPERATOR = 'AK_OPERATOR';
    case PL_MANAGER = 'PL_MANAGER';
    case PL_OPERATOR = 'PL_OPERATOR';
    case READ_ONLY = 'READ_ONLY';

    public function isAdministrator(): bool
    {
        return $this === self::AK_ADMIN;
    }

    public function belongsTo(OrganizationCode $organization): bool
    {
        return match ($organization) {
            OrganizationCode::AUTOKLUBY => in_array($this, [self::AK_ADMIN, self::AK_OPERATOR, self::READ_ONLY], true),
            OrganizationCode::POLAND => in_array($this, [self::PL_MANAGER, self::PL_OPERATOR, self::READ_ONLY], true),
        };
    }
}
