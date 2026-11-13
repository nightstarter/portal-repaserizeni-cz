# Fáze 3C – akceptace celé Fáze 3

Datum auditu: 2026-11-13. Výsledek: **přijato s níže uvedenými provozními omezeními**.

Audit neprovedl push, produkční migraci, import ani zápis do produkční databáze. Jediné produkční DB operace byly read-only `migrate:status`, `SELECT VERSION()`, počty a dotazy do `information_schema`. Počty `shipments`, `shipment_items` a `shipment_events` byly před i po testech shodně `0 / 0 / 0`.

## Historie commitů

Vstupní `git log --oneline origin/main..HEAD` na začátku 3C obsahoval čistě oddělené změny:

```text
6c0bf64 feat: add phase 3B shipment UI
9b1b88a feat: add phase 3A shipment domain
03fb023 fix: isolate tests after database incident
```

Během akceptace byly nalezeny a samostatně opraveny dvě chyby:

```text
a14ab30 fix: hide tracking for pickup shipments
e415a5f fix: bound shipment batch prevalidation queries
```

První oprava odstraňuje N+1 při předvalidaci dávky, druhá odstraňuje zavádějící tracking ze svozu AUTOKLUBY. Historie nebyla přepisována, slučována ani rebasována.

## Bezpečné testovací prostředí

- Produkční `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `LEGACY_SHOP_IMPORT_ENABLED=false`, `POHODA_BRIDGE_ENABLED=false`.
- `.env.testing` existuje, je ignorovaný pravidlem `/.env.*`, není tracked a má oprávnění `600`.
- `.env.testing`, `.env.testing.example` i `phpunit.xml` určují `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.
- `scripts/assert-test-database.php` je fail-fast branou `composer test`; odmítá chybějící soubor, jiná oprávnění a neizolované DB.
- `DatabaseCommandGuard` v testing vždy odmítne `portal_repaserizeni`; jinou serverovou testovací DB dovolí jen s explicitním opt-in a testovacím názvem.
- Tentýž guard v production blokuje `migrate:fresh`, `migrate:refresh`, `migrate:reset` a `db:wipe`; test pokrývá všechny čtyři příkazy a ověřuje, že `--force` politiku nepřebije. Žádný destruktivní příkaz nebyl při 3C spuštěn.
- `composer check` obsahuje Composer validaci, Pint, syntax audit a bezpečný test runner; ruční `migrate:fresh` neobsahuje.
- `docs/INCIDENT_2026-11-12.md` neobsahuje hesla, přístupové údaje ani osobní data.

## Automatizovaná akceptace

Příkaz `php8.4 composer.phar check` prošel celý: **272 testů, 904 assertions**. Před testy vypsal `Test database guard: isolated SQLite :memory: connection confirmed.`

Zásilkový výřez (`ShipmentWorkflowTest` a `Phase3bShipmentUiTest`) po rozšíření obsahuje **23 testů, 166 assertions** a pokrývá:

- oba směry a všechny čtyři povolené kombinace GLS/DPD/SVOZ;
- povinný tracking GLS/DPD, zakázaný tracking svozu a unikátní `(carrier, tracking)`;
- více objednávek a fyzických kusů v jedné zásilce;
- jednu aktivní rezervaci na item a zachování starých `shipment_items` při pozdější přepravě;
- roli, směr, fyzickou lokalitu, stav větve i serverem odvozenou akci;
- atomické hromadné odeslání a příjem, včetně společné dávky custom/stock;
- úplný rollback odeslání i příjmu při jediné vadné položce;
- `TRANSPORT_ERROR`, povinný popis, zachovanou rezervaci a pozdější doručení;
- globální idempotenci, replay, stale `lock_version`, duplicitní tracking a souběžné požadavky;
- role AUTOKLUBY/POLSKO/read-only a serverové zamítnutí přímého nepovoleného POST;
- seznam/detail/historii omezené na sender/recipient organizaci; současný model má právě tyto dvě organizace, takže obě jsou účastníkem každé meziorganizační zásilky;
- QR token, celou QR URL, kamerový `BarcodeDetector` fallback, neplatný, směrově cizí a duplicitní QR;
- CSRF, serverovou validaci, CZ/PL texty a opakovaný HTTP submit bez druhé akce;
- regresi celých `CUSTOM_REBUILD` a `STOCK_REBUILD` sad v plném `composer check`.

### Dávka 100 položek

Nový test připraví zásilku se 100 itemy, zneplatní poslední větev a spustí odeslání. Původně naměřil 206 SELECTů, čímž odhalil N+1. Po opravě jsou objednávky a thread eventy načteny dávkově a předvalidace musí zůstat pod pevnou mezí 10 SELECTů. Test zároveň ověřuje, že zásilka zůstane `PREPARED`, všech 100 vazeb zůstane konzistentních, 99 platných větví se neposune a nevznikne žádný shipment/thread dispatch event.

## UI akceptace

Automatizované HTTP/Blade testy potvrzují, že obsah zásilky mění pouze sender v `PREPARED`, sender zásilku odešle, recipient ji přijme a read-only uživatel nemá mutační ovládání. Přímé nepovolené POSTy končí `403`, takže skrytí tlačítka není autorizační hranice. Detail vykresluje číslo, směr, carrier, stav, tracking jen pro GLS/DPD, položky, zakázky, větve a historii. Responzivní tabulky a breakpoint do 700 px jsou součástí layoutu.

Browser/computer-use nástroj nebyl v této relaci dostupný, proto nebyl proveden skutečný browser smoke test ani test kamery. Nebyla vytvořena produkční demo data. Ruční checklist je v `docs/PHASE_3_DEPLOYMENT.md`.

## Provozní audit

- `php8.4 artisan schedule:list`: žádné definované úlohy.
- Neběžel portalový `artisan schedule`, queue ani legacy import proces. Uživatelský crontab obsahuje jiné systémy, ale žádnou cestu/doménu tohoto portálu.
- `.env`, `.env.testing` a adresář `legacy-reference` nejsou tracked; `legacy-reference` leží mimo kořen Git repozitáře `web`.
- Sken tracked souborů nenašel privátní klíče, známé cloud/GitHub/Slack tokeny ani neprázdné produkční secret assignmenty. Sledované `.env*.example` obsahují pouze prázdné placeholdery.
- Produkční data se testy nemohla měnit: Composer guard potvrdil SQLite `:memory:` a produkční shipment počty se nezměnily.

## Známá omezení

- Skutečný browser a fyzická kamera nebyly v 3C otestovány; `BarcodeDetector` je progresivní rozšíření a fallback je hardwarová čtečka nebo ruční token/URL.
- Není implementováno carrier API, příloha chyby přepravy, zákaznická přeprava, finance, detail vratky, dokumenty, diskuse ani notifikace.
- Zápis 100 úspěšných položek je z principu lineární, protože každý thread musí vytvořit vlastní event a audit; odstraněné N+1 se týkalo redundantního čtení během předvalidace.
- Akceptace neopravňuje zapnutí importu, Pohoda bridge ani scheduleru a nezahajuje Fázi 4.

