# Rozhodnutí

## D-001: Laravel 13 a PHP 8.5

- Datum: 2026-11-11
- Stav: přijato podle IMPLEMENTACE v18
- Kontext: Aktuální stabilní Laravel 13 vyžaduje PHP 8.3+. Web i CLI nyní používají PHP 8.5.12 se všemi potřebnými moduly.
- Rozhodnutí: Cílit na Laravel 13/PHP 8.5. Nepoužít dostupný zastaralý Laravel 10.
- Dopad: Produkční web, CLI, cron a CI musí používat PHP 8.5.

## D-002: Import zůstává mimo Fázi 0

- Datum: 2026-11-11
- Stav: přijato podle IMPLEMENTACE v18
- Rozhodnutí: Ve Fázi 0 nevytvářet legacy DB připojení, importní příkaz ani scheduler. Zachovat pouze bezpečně vypnutou konfigurační hodnotu `LEGACY_SHOP_IMPORT_ENABLED=false`.
