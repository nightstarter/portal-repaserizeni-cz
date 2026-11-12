<?php

namespace App\Enums;

enum ShipmentDirection: string
{
    case AK_TO_PL = 'AK_TO_PL';
    case PL_TO_AK = 'PL_TO_AK';

    public function sender(): OrganizationCode
    {
        return $this === self::AK_TO_PL ? OrganizationCode::AUTOKLUBY : OrganizationCode::POLAND;
    }

    public function recipient(): OrganizationCode
    {
        return $this === self::AK_TO_PL ? OrganizationCode::POLAND : OrganizationCode::AUTOKLUBY;
    }

    public function allowsCarrier(ShipmentCarrier $carrier): bool
    {
        return match ($this) {
            self::AK_TO_PL => in_array($carrier, [ShipmentCarrier::GLS, ShipmentCarrier::SVOZ_AUTOKLUBY], true),
            self::PL_TO_AK => in_array($carrier, [ShipmentCarrier::DPD, ShipmentCarrier::SVOZ_AUTOKLUBY], true),
        };
    }
}
