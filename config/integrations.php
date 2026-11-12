<?php

return [
    'legacy_shop' => [
        'enabled' => (bool) env('LEGACY_SHOP_IMPORT_ENABLED', false),
        'import_from' => env('LEGACY_SHOP_IMPORT_FROM'),
        'minimum_order_id' => env('LEGACY_SHOP_MIN_ORDER_ID'),
        'batch_size' => (int) env('LEGACY_SHOP_IMPORT_BATCH_SIZE', 100),
        'prestashop14' => [
            'table_prefix' => env('LEGACY_SHOP_PRESTASHOP_PREFIX', 'ps_'),
            'paid_state_ids' => json_decode((string) env('LEGACY_SHOP_PAID_STATE_IDS_JSON', '[2]'), true) ?: [],
            'carrier_handover_map' => json_decode((string) env('LEGACY_SHOP_CARRIER_HANDOVER_MAP_JSON', '{"19":"PERSONAL_PICKUP","20":"PERSONAL_PICKUP"}'), true) ?: [],
        ],
        'mapping' => [
            'verified' => (bool) env('LEGACY_SHOP_MAPPING_VERIFIED', false),
            'product_classifications' => json_decode((string) env('LEGACY_SHOP_PRODUCT_CLASSIFICATIONS_JSON', '[]'), true) ?: [],
        ],
    ],
    'pohoda_bridge' => [
        'enabled' => (bool) env('POHODA_BRIDGE_ENABLED', false),
        'key_id' => env('POHODA_BRIDGE_KEY_ID'),
        'secret' => env('POHODA_BRIDGE_SECRET'),
    ],
];
