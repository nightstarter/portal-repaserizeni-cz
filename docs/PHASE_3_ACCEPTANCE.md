# Fáze 3C – akceptace celé Fáze 3

Datum auditu: 2026-11-13. Výsledek po opravě 3D: **automatizovaná i potvrzená ruční browserová akceptace jsou zelené a Fáze 3 je přijata**. Mobilní platforma zatím potvrzena nebyla a zůstává samostatnou ruční kontrolou.

Audit neprovedl push, produkční migraci, import ani zápis do produkční databáze. Jediné produkční DB operace byly read-only `migrate:status`, `SELECT VERSION()`, počty a dotazy do `information_schema`. Při finální kontrole po ručním testu byly počty `shipments`, `shipment_items` a `shipment_events` před i po `composer check` shodně `1 / 0 / 1`; izolovaný testovací běh tedy produkční DB nezměnil a do existující testovací zásilky nepřidal žádnou položku.

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

Hotfix 3D je rovněž samostatný commit:

```text
96d6e7d fix: add compatible shipment QR camera
```

V aktuálním `origin/main..HEAD` je hotfix 3D; finální dokumentační commit bude druhým commitem tohoto rozsahu. Commity 3A–3C už jsou součástí `origin/main`.

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

Příkaz `php8.4 composer.phar check` prošel celý: **273 testů, 944 assertions**. Před testy vypsal `Test database guard: isolated SQLite :memory: connection confirmed.`

Zásilkový výřez (`ShipmentWorkflowTest` a `Phase3bShipmentUiTest`) po rozšíření obsahuje **24 testů, 206 assertions** a pokrývá:

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
- QR token, celou QR URL, progressive kamerový dekodér (`BarcodeDetector` nebo lokální jsQR), neplatný, směrově cizí a duplicitní QR;
- CSRF, serverovou validaci, CZ/PL texty a opakovaný HTTP submit bez druhé akce;
- regresi celých `CUSTOM_REBUILD` a `STOCK_REBUILD` sad v plném `composer check`.

### Dávka 100 položek

Nový test připraví zásilku se 100 itemy, zneplatní poslední větev a spustí odeslání. Původně naměřil 206 SELECTů, čímž odhalil N+1. Po opravě jsou objednávky a thread eventy načteny dávkově a předvalidace musí zůstat pod pevnou mezí 10 SELECTů. Test zároveň ověřuje, že zásilka zůstane `PREPARED`, všech 100 vazeb zůstane konzistentních, 99 platných větví se neposune a nevznikne žádný shipment/thread dispatch event.

## UI akceptace

Automatizované HTTP/Blade testy potvrzují, že obsah zásilky mění pouze sender v `PREPARED`, sender zásilku odešle, recipient ji přijme a read-only uživatel nemá mutační ovládání. Přímé nepovolené POSTy končí `403`, takže skrytí tlačítka není autorizační hranice. Detail vykresluje číslo, směr, carrier, stav, tracking jen pro GLS/DPD, položky, zakázky, větve a historii. Responzivní tabulky a breakpoint do 700 px jsou součástí layoutu.

Finální ruční browser test potvrdil spuštění kamery, rozpoznání testovací QR URL a bezpečnou 404/validační chybu pro neexistující token. Do zásilky se nic nepřidalo. Konkrétní desktopový OS ani název a verze browseru nebyly v testovacím záznamu uvedeny, proto nejsou v dokumentaci domýšleny. Mobilní browser nebyl potvrzen a zůstává zbývající ruční kontrolou podle `docs/PHASE_3_DEPLOYMENT.md`.

## Oprava 3D – kompatibilní QR kamera

Diagnostika původní implementace našla příčinu zdánlivě aktivního tlačítka bez reakce. Při chybějícím `BarcodeDetector` nebo `mediaDevices` skript nastavil `button.hidden=true` a skončil bez click handleru, ale globální CSS `.button { display:inline-block }` přebilo atribut `hidden`. Tlačítko tedy zůstalo viditelné, i když JavaScript kameru vůbec neinicializoval. Původní kód navíc nekontroloval `BarcodeDetector.getSupportedFormats()`, neměl dekódovací fallback, desktopový retry, přepnutí kamery ani úplné ukončení tracků ve všech chybových cestách.

Read-only HTTPS kontrola produkční odpovědi v 3D nenašla explicitní `Permissions-Policy` ani CSP hlavičku blokující kameru. Konkrétní stav uživatelského oprávnění, dostupnost `mediaDevices`, seznam formátů `BarcodeDetector` ani obsazenost zařízení nelze bez daného browseru zjistit předem; nový skript je proto kontroluje za běhu a každý výsledek zobrazí lokalizovaně.

3D mění výchozí stav tlačítka na serverem renderované `disabled` + `aria-disabled=true`. Lokální skript jej povolí až po registraci handlerů a úspěšném ověření HTTPS, permissions policy, `mediaDevices.getUserMedia` a alespoň jednoho QR dekodéru. Globální `[hidden] { display:none!important }` zabraňuje opakování CSS chyby.

Po kliknutí se synchronně zobrazí „Spouštím kameru…“. Skript žádá `facingMode: {ideal: 'environment'}` a při nedostupné zadní kameře opakuje požadavek s `video:true`. Po udělení oprávnění nabídne výběr více `videoinput` zařízení. Kamera má ruční stop a všechny tracky se zastaví při stopu, změně kamery, chybě, úspěšném skenu, `pagehide` i `beforeunload`.

Nativní `BarcodeDetector` se použije pouze tehdy, když `getSupportedFormats()` obsahuje `qr_code`. Jinak se použije lokálně hostovaný `jsQR 1.4.0`, Apache-2.0, z oficiálního npm tarballu s připnutou integritou a SHA-256 uvedenými v `public/vendor/jsqr/1.4.0/README.md`. Runtime neobsahuje CDN ani externí script. Canvas, snímky i video zůstávají pouze v paměti browseru; server dostane až dekódovaný řetězec stejným CSRF/idempotentním formulářem jako ruční token/URL.

Renderovací testy ověřují ruční vstup bez kamery, bezpečný počáteční stav, lokální a verzované assety, licenci a checksum, absenci CDN, lifecycle tracků, CZ/PL chybové texty a serverovou validaci samostatného tokenu i celé QR URL.

## Provozní audit

- `php8.4 artisan schedule:list`: žádné definované úlohy.
- Neběžel portalový `artisan schedule`, queue ani legacy import proces. Uživatelský crontab obsahuje jiné systémy, ale žádnou cestu/doménu tohoto portálu.
- `.env`, `.env.testing` a adresář `legacy-reference` nejsou tracked; `legacy-reference` leží mimo kořen Git repozitáře `web`.
- Sken tracked souborů nenašel privátní klíče, známé cloud/GitHub/Slack tokeny ani neprázdné produkční secret assignmenty. Sledované `.env*.example` obsahují pouze prázdné placeholdery.
- Produkční data se testy nemohla měnit: Composer guard potvrdil SQLite `:memory:` a produkční shipment počty zůstaly před i po běhu `1 / 0 / 1`.

## Známá omezení

- Potvrzený browserový test neobsahuje identifikaci desktopového OS/browseru, proto nelze přijmout jmenovitou platformní matici Chrome/Edge.
- Mobilní kamera a permission UX na Android Chrome a iPhone Safari nebyly potvrzeny a zůstávají ruční kontrolou mimo uzavření Fáze 3.
- Není implementováno carrier API, příloha chyby přepravy, zákaznická přeprava, finance, detail vratky, dokumenty, diskuse ani notifikace.
- Zápis 100 úspěšných položek je z principu lineární, protože každý thread musí vytvořit vlastní event a audit; odstraněné N+1 se týkalo redundantního čtení během předvalidace.
- Akceptace neopravňuje zapnutí importu, Pohoda bridge ani scheduleru a nezahajuje Fázi 4.

