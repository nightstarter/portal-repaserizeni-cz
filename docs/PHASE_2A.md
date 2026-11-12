# Fáze 2A — doménový stavový automat zakázkové repase

Datum dokončení: 2026-11-12.

## Rozsah

Fáze implementuje pouze větev `CUSTOM_REBUILD` nad existujícími modely `Order`, `ProcessThread`, `Item`, `ThreadEvent` a `AuditLog`.

Implementováno:

- explicitní akce a povolené přechody GLS i osobního odběru;
- serverová organizační a rolová oprávnění;
- `ADMIN_OVERRIDE` pouze pro `AK_ADMIN`, vždy s neprázdným důvodem;
- řádkový zámek, optimistic `lock_version`, idempotency key a transakční zápis stavu, události a auditu;
- atomická kontrolní data prvního příjmu včetně routingu konkrétní objednávky a příznaku axiálních táhel;
- idempotentní počáteční doména: jedna větev, jeden fyzický zákaznický kus, náhodný 48znakový QR token, lokalita `CUSTOMER`;
- dokončení pouze v `SENT_CUSTOMER` nebo `PICKED_UP`;
- HTTP command endpoint, který nepřijímá libovolný cílový stav pro běžnou akci.

## Databázová změna

Migrace `2026_11_12_000004_add_phase_2a_workflow_guards.php` přidává k `thread_events`:

- `idempotency_key`;
- `request_hash`;
- unikátní omezení `(process_thread_id, idempotency_key)`.

Migrace je pouze verzovaná v repozitáři. V rámci fáze nebyla spuštěna proti produkční databázi.

## Ověření

Testy používají výhradně Laravel factories a SQLite databázi v paměti. Pokrývají:

- celou cestu GLS a osobního odběru;
- všechny nepovolené kombinace stav–akce;
- nesprávnou organizaci a pokus podstrčit cílový stav;
- povinná data příjmu;
- routing objednávky bez změny produktového registru;
- opakovaný idempotency key;
- dva požadavky nad stejnou verzí větve;
- rollback při chybě zápisu události i auditu;
- override bez důvodu i auditovaný override;
- uzavření pouze v obou koncových stavech;
- idempotentní vytvoření počáteční větve a fyzického kusu.

## Bezpečnostní hranice

Fáze neobsahuje skladové workflow, zásilky, finance, doklady, diskusi, notifikace ani ostrý import. Scheduler ani produkční migrace nebyly spuštěny. `LEGACY_SHOP_IMPORT_ENABLED=false` zůstává beze změny.
