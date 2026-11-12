# Fáze 1D – integrační smlouva a připravenost importu

## Provozní hranice

Původní `objednavky.php` nadále řeší vztah zákazník–e-shop, platbu a schválení skladové nebo zakázkové varianty. Nový portál nepřebírá zákaznické schvalování; je mostem mezi AUTOKLUBY CZ a Polskem.

- `schvaleno=0`: čeká na klasifikaci, bez objednávky a integrační chyby; při dalších bězích se znovu kontroluje.
- `schvaleno=1`: `STOCK_REBUILD`.
- `schvaleno=2`: `CUSTOM_REBUILD`; podtyp `custom_repair`/`catalog_repair` zůstává neznámý, pokud nemá jednoznačný zdroj.
- `NULL` nebo jiná hodnota: viditelná integrační chyba.

## Podmínka úhrady

Provozovatel 2026-11-12 potvrdil, že autoritou úhrady je existence alespoň jednoho `ps_order_history.id_order_state=2` (`Payment accepted`). Nezáleží na tom, zda jde o poslední stav.

Živá read-only diagnostika potvrdila, proč nelze použít jiné zkratky:

- `total_paid`/`total_paid_real` byly pokryté i u nových objednávek ve stavu `Awaiting bank wire payment` bez historického stavu 2;
- většina historicky zpracovaných objednávek se `schvaleno=1/2` měla `valid=0`, včetně objednávek se stavem 2 nebo pozdějšího `Shipped`;
- poslední stav může být `Shipped`, `Vyplaceno`, `Vrácené staré řízení` či jiný stav po přijetí platby;
- v níže vzorkovaných objednávkách nebyl záznam v `ps_payment_cc` ani `ps_paypal_order`.

Importér proto vrací `PAYMENT_NOT_ACCEPTED`, pokud stav 2 v historii chybí. `valid`, poslední stav/název, částkové porovnání a dostupné platební záznamy zůstávají v anonymizované diagnostice, ale nerozhodují.

### Anonymizovaný produkční vzorek

Vzorek obsahuje pouze zdrojové ID, klasifikaci, stavové příznaky a produktovou referenci; žádné jméno, kontakt, adresu, VIN, částku ani celou objednávku.

| source_order_id | schvaleno | valid | poslední stav | historie stavu 2 | částka pokryta | produktová reference |
| --- | ---: | ---: | --- | --- | --- | --- |
| 653 | 1 | 0 | 10 – Awaiting bank wire payment | ne | ano | `RR603667` |
| 652 | 1 | 0 | 10 – Awaiting bank wire payment | ne | ano | `RR382007` |
| 648 | 1 | 0 | 2 – Payment accepted | ano | ano | `RR643816` |
| 646 | 1 | 0 | 2 – Payment accepted | ano | ano | `RRNR271333` |
| 654 | 2 | 0 | 2 – Payment accepted | ano | ano | `RR331797` |
| 651 | 2 | 0 | 2 – Payment accepted | ano | ano | `RR140298-1` |
| 650 | 2 | 0 | 10 – Awaiting bank wire payment | ne | ano | `RRNR442299` |
| 649 | 2 | 0 | 10 – Awaiting bank wire payment | ne | ano | `RR654100-1` |

## Technický typ a routing

Read-only průzkum názvů všech sloupců nenašel zdroj odpovídající technickému typu nebo routingu. Produktové features obsahují pouze rozměry, hmotnost, řadu a OEM údaje. `ps_product.shop`, název ani tvar reference nejsou potvrzenou autoritou a nesmí se použít jako heuristika.

Portálový registr `legacy_product_configurations` používá přesnou `product_reference` a ukládá:

- `technical_type`: `WORM`, `HYDRAULIC` nebo `EPS`,
- `default_routing`: `KRAFT` nebo `ARGO`,
- `verification_status`: `UNVERIFIED`/`VERIFIED`,
- `confirmed_by` a `confirmed_at`.

Chybějící, neúplný nebo neověřený záznam vrací předvídatelný výsledek `PRODUCT_CONFIGURATION_REQUIRED`. Při zápisovém běhu by šlo o viditelný neúspěšný integrační event pro AUTOKLUBY; objednávka se nevytvoří a nic se nepředá Polsku.

## Skladový scénář

PrestaShop není autoritou dostupnosti skladu. `STOCK_REBUILD` bez aktuálního Pohoda snapshotu používá scénář a stav `PENDING_STOCK_CHECK`. Vytvoří pouze `STOCK_REQUEST` odpovědný AUTOKLUBY. Nevytvoří `STOCK_REPLENISHMENT`, žádné vlákno odpovědné Polsku a žádný požadavek viditelný Polsku.

## Živé dry-runy

Před i po obou bězích byly počty portálových tabulek shodné: `orders=0`, `integration_events=0`, `integration_checkpoints=0`. Legacy účet byl používán pouze přes `SELECT`/`SHOW`.

### STOCK_REBUILD

- `source_order_id`: `648`
- candidate hash: `c563b26b726d3b6895317ff733afd5f139439a353550837feff7410bb2268c01`
- úhrada: ano, historický stav 2; poslední stav `2 – Payment accepted`; `valid=0` je pouze diagnostika
- repair type: `STOCK_REBUILD`
- product reference: `RR643816`
- technický typ/routing: chybí, `PRODUCT_CONFIGURATION_REQUIRED`
- stock decision: `PENDING_STOCK_CHECK`
- import eligibility: ne; `CUSTOMER_HANDOVER_UNCONFIRMED`, `PRODUCT_CONFIGURATION_REQUIRED`, `MAPPING_NOT_VERIFIED`

### CUSTOM_REBUILD

- `source_order_id`: `654`
- candidate hash: `ef90d227482fdf5e50420525b62236541c250f8ddd467731875df60711adcb36`
- úhrada: ano, historický stav 2; poslední stav `2 – Payment accepted`; `valid=0` je pouze diagnostika
- repair type: `CUSTOM_REBUILD`
- product reference: `RR331797`
- technický typ/routing: chybí, `PRODUCT_CONFIGURATION_REQUIRED`
- stock decision: `NOT_APPLICABLE`
- import eligibility: ne; `CUSTOMER_HANDOVER_UNCONFIRMED`, `PRODUCT_CONFIGURATION_REQUIRED`, `MAPPING_NOT_VERIFIED`
- další varování: `REPAIR_SUBTYPE_UNCONFIRMED`

Výstup neobsahuje zákaznické údaje, adresy, VIN, názvy produktů ani částky.

## Testy a bezpečnost

Automatické testy pokrývají:

- změny `schvaleno 0 → 1` a `0 → 2` přes vyšší checkpoint,
- odmítnutí objednávky bez historického stavu 2 i při pokryté částce,
- přijetí historického stavu 2 i po pozdějším jiném stavu,
- `PRODUCT_CONFIGURATION_REQUIRED` pro neznámý produkt,
- `PENDING_STOCK_CHECK` bez polské větve,
- absenci PII v dry-run výsledku,
- pouze read-only dotazy do legacy fixture,
- idempotenci a nulové portálové zápisy dry-runu,
- kompatibilní introspekci starého MySQL přes `SHOW TABLES`, `SHOW COLUMNS` a `SELECT VERSION()`.

## Stop bod

`LEGACY_SHOP_IMPORT_ENABLED=false` a `LEGACY_SHOP_MAPPING_VERIFIED=false` zůstávají beze změny. Nebyl spuštěn ostrý import, scheduler, migrace produkční databáze ani Fáze 2. Před budoucím zapnutím je nutné nasadit migraci produktového registru, naplnit a ověřit konfigurace produktů, uzavřít zbývající mapu předání a dodat Pohoda snapshot kontrakt.
