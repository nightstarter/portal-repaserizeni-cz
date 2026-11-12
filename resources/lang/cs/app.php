<?php

return [
    'dashboard' => 'Přehled', 'users' => 'Uživatelé', 'logout' => 'Odhlásit',
    'phase_zero_ready' => 'Bezpečný základ Fáze 0 je implementován. Provozní workflow bude implementováno v dalších fázích.',
    'phase_1a_ready' => 'Datové jádro Fáze 1A používá testovací data. Produkční import zůstává vypnutý.',
    'organization' => 'Organizace', 'role' => 'Role', 'import_disabled' => 'Produkční import objednávek je vypnutý.',
    'change_password' => 'Změnit heslo', 'current_password' => 'Současné heslo', 'new_password' => 'Nové heslo',
    'password_confirmation' => 'Potvrzení hesla', 'save' => 'Uložit', 'password_changed' => 'Heslo bylo změněno.',
    'password_change_required' => 'Před pokračováním změňte dočasné heslo.', 'name' => 'Jméno', 'active' => 'Aktivní',
    'yes' => 'Ano', 'no' => 'Ne', 'create_user' => 'Vytvořit uživatele', 'language' => 'Jazyk',
    'user_created' => 'Uživatel byl vytvořen.', 'user_updated' => 'Uživatel byl upraven.',
    'invalid_role_organization' => 'Role neodpovídá organizaci.', 'cannot_deactivate_self' => 'Nelze deaktivovat vlastní účet.',
    'orders' => 'Zakázky', 'orders_total' => 'Zakázek celkem', 'waiting_for_us' => 'Čeká na nás',
    'waiting_for_poland' => 'Čeká na Polsko', 'integration_errors' => 'Integrační chyby', 'order_no' => 'Číslo zakázky',
    'vehicle' => 'Vozidlo', 'order_type' => 'Typ zakázky', 'active_threads' => 'Procesní větve',
    'next_responsibility' => 'Kdo je na tahu', 'no_orders' => 'Žádné zakázky k zobrazení.',
    'product_code' => 'Kód produktu', 'technical_type' => 'Technický typ', 'routing' => 'Routing',
    'process_threads' => 'Procesní větve', 'physical_items' => 'Fyzické kusy', 'item_role' => 'Role kusu',
    'location' => 'Lokalita', 'no_items' => 'Zakázka zatím nemá fyzické kusy.', 'customer' => 'Zákazník',
    'read_only_phase_1a' => 'Tento základ detailu je ve Fázi 1A pouze pro čtení.',
    'order_types' => ['CUSTOM_REBUILD' => 'Zakázková repase', 'STOCK_REBUILD' => 'Skladová repase', 'BUYOUT' => 'Výkup'],
    'thread_types' => [
        'STOCK_REQUEST' => 'Zákaznické plnění', 'CUSTOM_REBUILD' => 'Zakázková repase', 'CORE_RETURN' => 'Vratka',
        'STOCK_REPLENISHMENT' => 'Doplnění skladu', 'WARRANTY' => 'Reklamace', 'BUYOUT' => 'Výkup',
    ],
    'thread_states' => [
        'WAITING_CUSTOMER_ITEM' => 'Čeká na řízení od zákazníka', 'READY_AK_DISPATCH' => 'Připraveno k expedici zákazníkovi',
        'WAITING_PL_STOCK' => 'Čeká na skladový kus z Polska', 'WAITING_CORE' => 'Čeká na vratný díl',
        'WAITING_AK_BUYOUT_REVIEW' => 'Čeká na posouzení výkupu',
    ],
    'responsible_parties' => ['CUSTOMER' => 'Zákazník', 'AUTOKLUBY' => 'AUTOKLUBY', 'POLAND' => 'Polsko', 'SYSTEM' => 'Systém'],
    'item_roles' => [
        'STOCK_ITEM' => 'Skladový kus', 'CUSTOMER_ITEM' => 'Zákaznický kus', 'CORE_RETURN' => 'Vratka',
        'STOCK_REPLACEMENT' => 'Náhradní skladový kus', 'WARRANTY_ITEM' => 'Reklamovaný kus', 'BUYOUT_ITEM' => 'Výkup',
    ],
];
