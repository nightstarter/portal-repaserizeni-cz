<?php

namespace PortalRepase\Bridge;

final class QuantityCalculator
{
    public static function calculate($physical, $reserved, $mode)
    {
        if ($mode === 'unconfirmed') {
            throw new BridgeException('QUANTITY_MODE_UNCONFIRMED');
        }
        if (! is_numeric($physical) || ! is_numeric($reserved)) {
            throw new BridgeException('QUANTITY_NOT_NUMERIC');
        }
        $physicalFloat = (float) $physical;
        $reservedFloat = (float) $reserved;
        if (floor($physicalFloat) !== $physicalFloat || floor($reservedFloat) !== $reservedFloat) {
            throw new BridgeException('QUANTITY_NOT_INTEGER');
        }
        if ($physicalFloat < 0 || $reservedFloat < 0) {
            throw new BridgeException('QUANTITY_NEGATIVE');
        }
        $physicalInt = (int) $physicalFloat;
        $reservedInt = (int) $reservedFloat;

        return array(
            'available_quantity' => $mode === 'physical' ? $physicalInt : max($physicalInt - $reservedInt, 0),
            'reserved_quantity' => $reservedInt,
            'physical_quantity' => $physicalInt,
            'free_quantity' => max($physicalInt - $reservedInt, 0),
        );
    }
}
