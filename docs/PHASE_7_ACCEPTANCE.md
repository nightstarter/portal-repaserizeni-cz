# Fáze 7 – závěrečná akceptace Pohoda bridge

Datum auditu: 20. 11. 2026. Rozsah: fáze 7A–7C proti MASTER v17, IMPLEMENTACE v18, bezpečnostním pravidlům projektu a provozním výsledkům potvrzeným vlastníkem. Fáze 8 nebyla zahájena.

## Výsledek

Fáze 7 je akceptována s níže uvedenými provozními omezeními. Audit našel dvě aplikační chyby: portálové API nevynucovalo HTTPS samo v middleware a diagnostika neodlišovala úplný snapshot od použitelného PARTIAL base runu. Obojí bylo opraveno a pokryto regresí. Přidány byly také kapacitní testy a kontroly CLI exit code a neměnnosti workflow/cen.

Ověřený provozní stav dodaný vlastníkem:

- HPSERVER komunikuje s portálem pouze odchozím HTTPS; HMAC a heartbeat fungují.
- SQL účet je read-only, rozsah je `RefSklad=1`, `RelSkTyp=1`, doslovný prefix `RR`, režim množství `free`.
- Použitelný base run je `PARTIAL`: 2482 položek `ACCEPTED + UNMAPPED`, dvě položky `DUPLICATE_PRODUCT_REFERENCE`, `receiving=0`, `failed=0`.
- Následné nezměněné běhy vytvářejí confirmations, nikoli nové položky; Task Scheduler běží každých pět minut.
- `PRODUCT_REPRICING=DEFERRED_EXTERNAL_SYSTEM`; produkční command whitelist je prázdný.

Tyto živé skutečnosti nebyly během akceptace znovu vyvolány: podle zadání nebylo přistupováno k produkční DB, Pohodě, portálu ani HPSERVERU a nebyl odeslán snapshot či confirmation.

## Bezpečnost

- Portál nemá Pohoda SQL connection ani DSN. Konektor nemá server/listener a neotvírá inbound port; tento provozní stav je doložen vlastníkem, nikoli vzdáleným skenem v této fázi.
- Všechny `/api/internal/v1/*` bridge routy vyžadují aplikačně HTTPS, zapnutý feature flag, HMAC-SHA256 a rate limit. Podpis je lowercase hex nad přesnými raw bytes a kanonickým query; porovnání používá `hash_equals`.
- Timestamp má maximální toleranci ±300 s. Nonce se ukládá až po platném podpisu, má unikátní omezení a replay se odmítá. Funguje současný i předchozí klíč.
- Server fail-closed povoluje jen warehouse `DECIN` a doslovný prefix `RR`. Prefix je parametr SQL `:prefix`; prázdné hodnoty, `%`, `_`, řídicí znaky a injection syntax jsou odmítnuty.
- `config.local.php`, state, spool a logy jsou Git-ignored. Audit, HTML a log sanitizace nepřenášejí secret, podpis, DSN, SQL heslo, raw body ani zákaznické PII.
- Produkční whitelist příkazů je prázdný. Neexistuje obecný SQL command, cenový command, přeceňovací endpoint ani UI. Testovací `NOOP` je povolen jen v testing prostředí.
- Oprávnění SQL účtu `SELECT` bez `INSERT/UPDATE/DELETE/ALTER` je provozně potvrzeno a `diagnose` jej kontroluje bez zkušebního zápisu.

## Snapshot, confirmation a resolver

- Dataset fingerprint je SHA-256 nad kanonicky seřazenými skladovými poli a manifestem anomálií. Nezávisí na pořadí SQL řádků ani neskladových polích; změna množství jej mění.
- `free = max(StavZ - Rezer, 0)` se počítá celočíselně bez zaokrouhlování. Záporná a necelá množství jsou anomálie, nikoli dostupnost.
- Nenamapované platné RR položky jsou použitelné. Duplicity jsou odmítnuté a jejich dostupnost je `UNKNOWN`.
- PARTIAL se nemění na COMPLETE. Confirmation je append-only, nepřenáší ani nemění množství/status; pouze obnoví freshness stejného nejnovějšího použitelného base runu.
- Změna fingerprintu, mismatch nebo ztráta lokálního state vede k novému full přenosu. Opakovaný batch a confirmation jsou idempotentní.
- Run v `RECEIVING`, run s chybějícím chunkem ani starší dokončený run nejsou použity místo novějšího platného datasetu.
- Resolver vrací pro čerstvou přijatou položku `AVAILABLE_IN_AK` při quantity > 0 a `NOT_AVAILABLE_IN_AK` při nule. Odmítnutá duplicita a chybějící položka v PARTIAL jsou `UNKNOWN`; chybějící položka v COMPLETE je `NOT_AVAILABLE_IN_AK`; expirované ověření je `STALE`.
- `STALE/UNKNOWN` ponechává workflow v `PENDING_STOCK_CHECK`. Snapshot nemění workflow, obchodní snapshot ani cenu existující objednávky.

## Kapacita a dotazy

Regresní test zpracoval 2484 řádků v pěti chunkech: 2482 přijato, dvě duplicity odmítnuty, base zůstal PARTIAL. Jedno confirmation nevytvořilo nový run ani položky. Simulovaných 288 confirmations za 24 hodin bylo zobrazeno v administraci s konstantním agregovaným počtem dotazů (limit testu ≤ 15, skutečný počet se neváže na počet položek/confirmations). Connector testy ověřují process lock, přesné spool bytes a stabilní batch ID, zaplněný/expirující spool a retry pro timeout/408/429/5xx.

Plný přenos po skutečné změně nadále ukládá položky. Retence nebo delta snapshoty jsou až kandidátem fáze 8 podle skutečného růstu DB.

## Migrace

Migrace 000014, 000015 a 000016 jsou rozšiřující, v pořadí na sebe navazují a používají typy, indexy, foreign keys a unique constraints kompatibilní s MariaDB 10.11. `up()` nemaže ani nepřepisuje existující snapshot historii. Kompletní testy je aplikují na izolovanou SQLite `:memory:`.

Produkční `migrate:status` nebyl v této fázi spuštěn. Dodaný živý výsledek s confirmations dokládá provoz schématu 000016, nejde však o nezávislý dotaz této akceptace.

## Neprovedené ruční kontroly a omezení

- Nebyl proveden nový síťový scan HPSERVERU, kontrola Task Scheduler GUI/Last Run Result, `fn_my_permissions`, Windows Time, živá rotace klíče ani řízený výpadek SQL/HTTPS.
- Nebyl poslán živý snapshot/confirmation a nebyla otevřena produkční administrace.
- Přesné hodnoty dvou duplicitních IDS nejsou v repozitáři ani zadání; akceptován je potvrzený počet a kód chyby. Kódy se mají převzít z existujícího bezpečného `stock:anomalies` reportu, nikoli znovu odhadovat.
- SMTP delivery ani jiné fáze nebyly součástí tohoto auditu.
- PHP CLI 7.4.33 je EOL technický dluh. Upgrade na izolované PHP CLI 8.2+ je úkol fáze 8.
- Externí HTTPS/HMAC je at-least-once přenos s doménovou idempotencí, nikoli distribuovaná exactly-once garance.
