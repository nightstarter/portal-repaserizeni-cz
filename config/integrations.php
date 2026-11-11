<?php

return [
    'legacy_shop' => [
        'enabled' => (bool) env('LEGACY_SHOP_IMPORT_ENABLED', false),
        'import_from' => env('LEGACY_SHOP_IMPORT_FROM'),
        'minimum_order_id' => env('LEGACY_SHOP_MIN_ORDER_ID'),
    ],
    'pohoda_bridge' => [
        'enabled' => (bool) env('POHODA_BRIDGE_ENABLED', false),
        'key_id' => env('POHODA_BRIDGE_KEY_ID'),
        'secret' => env('POHODA_BRIDGE_SECRET'),
    ],
];
