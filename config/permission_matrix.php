<?php

use App\Enums\UserRole;

/*
|--------------------------------------------------------------------------
| Phase 8A permission matrix
|--------------------------------------------------------------------------
|
| This is the single readable inventory of role capabilities. Row-level
| order/thread/item/shipment/file checks remain mandatory in policies and
| domain guards; a true value never bypasses ownership or organization scope.
|
*/
return [
    'roles' => [
        UserRole::AK_ADMIN->value => [
            'dashboard' => true, 'order_view' => true, 'customer_pii' => true,
            'customer_prices' => true, 'documents' => true, 'discussion' => true,
            'shipments' => true, 'core_return' => true, 'warranty' => true,
            'buyback' => true, 'manage_users' => true, 'manage_notifications' => true,
            'pohoda_diagnostics' => true, 'production_readiness' => true,
            'admin_override' => true, 'domain_write' => true,
        ],
        UserRole::AK_OPERATOR->value => [
            'dashboard' => true, 'order_view' => true, 'customer_pii' => true,
            'customer_prices' => true, 'documents' => true, 'discussion' => true,
            'shipments' => true, 'core_return' => true, 'warranty' => true,
            'buyback' => true, 'manage_users' => false, 'manage_notifications' => false,
            'pohoda_diagnostics' => false, 'production_readiness' => false,
            'admin_override' => false, 'domain_write' => true,
        ],
        UserRole::PL_OPERATOR->value => [
            'dashboard' => true, 'order_view' => true, 'customer_pii' => false,
            'customer_prices' => false, 'documents' => true, 'discussion' => true,
            'shipments' => true, 'core_return' => true, 'warranty' => true,
            'buyback' => true, 'manage_users' => false, 'manage_notifications' => false,
            'pohoda_diagnostics' => false, 'production_readiness' => false,
            'admin_override' => false, 'domain_write' => true,
        ],
        UserRole::READ_ONLY->value => [
            'dashboard' => true, 'order_view' => true, 'customer_pii' => false,
            'customer_prices' => false, 'documents' => true, 'discussion' => true,
            'shipments' => true, 'core_return' => true, 'warranty' => true,
            'buyback' => true, 'manage_users' => false, 'manage_notifications' => false,
            'pohoda_diagnostics' => false, 'production_readiness' => false,
            'admin_override' => false, 'domain_write' => false,
        ],
    ],
    'rules' => [
        'row_scope_always_required' => true,
        'foreign_resource_returns_404' => true,
        'poland_customer_pii_denied' => true,
        'poland_customer_prices_denied' => true,
        'read_only_writes_denied' => true,
    ],
];
