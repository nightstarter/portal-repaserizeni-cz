# Fáze 3A – doména zásilek AUTOKLUBY ↔ POLSKO

Fáze 3A implementuje pouze doménu a command služby hromadných zásilek mezi AUTOKLUBY a Polskem. Neobsahuje UI, QR skener, zákaznickou přepravu, carrier API, finance, dokumenty ani diskusi.

## Implementováno

- `shipments` s náhodným veřejným číslem, směrem, dopravcem, trackingem, stavem, organizacemi, aktérem, časovými údaji, popisem chyby a `lock_version`;
- historické `shipment_items` s vazbou na přesný fyzický item a procesní thread;
- neměnné idempotentní `shipment_events`;
- DB ochrana aktivní rezervace přes `items.active_shipment_id`;
- povolená matice `AK_TO_PL`/`PL_TO_AK` a `GLS`/`DPD`/`SVOZ_AUTOKLUBY`;
- vytvoření prázdné `PREPARED` zásilky, přidání a odebrání itemu;
- atomické hromadné odeslání přes existující workflow služby;
- atomický hromadný příjem s odvozenou příjmovou akcí a aktualizací fyzické lokality;
- transportní chyba bez změny threadů a bez uvolnění rezervace;
- idempotence, request hash, optimistic verze a row locks;
- auditovaný override vytvoření zásilky pro `AK_ADMIN` s povinným důvodem.

Přesná procesní a tracking matice je v `docs/SHIPMENTS.md`; stavové souvislosti jsou také v `docs/STATE_MACHINES.md`.

## Migrace

`2026_11_12_000006_create_phase_3a_shipments` vytváří `shipments`, `shipment_items`, `shipment_events` a přidává nullable FK `items.active_shipment_id`. Migrace je rozšiřující v `up()` a nemaže ani nepřepisuje existující data. Produkční migrace nebyla spuštěna. Při budoucím nasazení musí následovat až po dosud pending migraci `2026_11_12_000005_add_phase_2c_domain_guards`.

Rollback odstraní nejprve FK aktivní rezervace a potom nové tabulky. Po vzniku produkční historie zásilek je tento rollback datově destruktivní a musí být nahrazen obnovou zálohy nebo nápravnou migrací.

## Testy

`ShipmentWorkflowTest` pokrývá:

- všechny čtyři povolené kombinace směru a dopravce;
- tracking GLS/DPD, zákaz trackingu u svozu a unikátní carrier/tracking;
- více objednávek v jedné zásilce;
- dvojí item v jedné i dvou aktivních zásilkách;
- špatný směr, organizaci, stav a nesouvisející thread;
- atomické hromadné odeslání i příjem a rollback při jediné vadné položce;
- příjmové akce pro `CUSTOM_REBUILD`, `STOCK_REQUEST` a `STOCK_REPLENISHMENT`;
- povinný popis chyby, zachování rezervace a pozdější doručení;
- idempotentní replay, konflikt jiného payloadu a konflikt zastaralé verze přidání/odeslání;
- auditovaný `AK_ADMIN` override.

Celá původní sada zůstává regresí `CUSTOM_REBUILD`, `STOCK_REBUILD`, UI Fáze 2 a importní domény. Testy používají pouze SQLite `:memory:` a factories.

Po zavedení incidentní DB pojistky prošel izolovaný `composer check`: **263 testů, 838 assertions**. Před testy proběhla fail-fast kontrola `.env.testing` a SQLite `:memory:`; žádná serverová migrace nebyla spuštěna.

## Mimo rozsah a provozní stav

- Fáze 3B ani její UI/skener nebyly zahájeny.
- Produkční migrace, scheduler a import nebyly spuštěny.
- `LEGACY_SHOP_IMPORT_ENABLED=false` zůstává beze změny.
- Tracking je zatím ručně předaný command službě; žádné carrier API nebo webhook není zapojen.
- Čekající `CORE_RETURN` není přepravitelný, dokud Fáze 4 nezavede jeho příjmové a odesílací stavy.
