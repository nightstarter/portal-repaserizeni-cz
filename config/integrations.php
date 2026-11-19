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
            'carrier_handover_map' => json_decode((string) env('LEGACY_SHOP_CARRIER_HANDOVER_MAP_JSON', '{"18":"GLS","19":"PERSONAL_PICKUP","20":"PERSONAL_PICKUP"}'), true) ?: [],
        ],
        'mapping' => [
            'verified' => (bool) env('LEGACY_SHOP_MAPPING_VERIFIED', false),
        ],
    ],
    'pohoda_bridge' => [
        'enabled' => (bool) env('POHODA_BRIDGE_ENABLED', false),
        'key_id' => env('POHODA_BRIDGE_KEY_ID'),
        'secret' => env('POHODA_BRIDGE_SECRET'),
        'previous_key_id' => env('POHODA_BRIDGE_PREVIOUS_KEY_ID'),
        'previous_secret' => env('POHODA_BRIDGE_PREVIOUS_SECRET'),
        'timestamp_tolerance' => (int) env('POHODA_BRIDGE_TIMESTAMP_TOLERANCE', 300),
        'stale_minutes' => (int) env('POHODA_BRIDGE_STALE_MINUTES', 30),
        'max_batch_items' => (int) env('POHODA_BRIDGE_MAX_BATCH_ITEMS', 500),
        'max_body_bytes' => (int) env('POHODA_BRIDGE_MAX_BODY_BYTES', 2097152),
        'ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('POHODA_BRIDGE_IP_ALLOWLIST', ''))))),
        'rate_limit_per_minute' => (int) env('POHODA_BRIDGE_RATE_LIMIT_PER_MINUTE', 60),
        'command_lease_minutes' => (int) env('POHODA_BRIDGE_COMMAND_LEASE_MINUTES', 5),
        'allowed_command_types' => [],
    ],
];
