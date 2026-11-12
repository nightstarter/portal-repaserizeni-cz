# Fáze 1C – specializovaný read-only importér PrestaShop 1.4.11

## Rozsah

Fáze 1C nahrazuje obecné jednotabulkové mapování specializovaným `PrestaShop14OrderReader`. Reader používá pouze parametrizované `SELECT` dotazy a pevně definované read-only JOINy nad produkčním schématem PrestaShopu 1.4.11.

Zdroj objednávek je výhradně `ps_orders`. Tabulka `ps_poptavky` obsahuje pouze poptávky, nemá jednoznačnou vazbu na `id_order` a některé objednávky vznikají bez poptávky. Importér ji proto nepoužívá ani nevyžaduje.

## Identifikace

- `source_order_id` je původní `ps_orders.id_order` a používá se pro idempotenci.
- Viditelné `order_no` je externí číslo Pohody `4001 + id_order`.
- Obě hodnoty zůstávají oddělené.

## Read-only dotazy

Základ objednávky se skládá z:

- `ps_orders o`,
- `ps_customer c` přes `o.id_customer`,
- fakturační `ps_address a` přes `o.id_address_invoice`,
- země a dopravce přes jejich číselníky,
- poslední `ps_order_history` podle nejvyššího `id_order_history`,
- vozidla z `ps_cart_vehicle_data` přes `id_cart`,
- položek z `ps_order_detail` a produktu z `ps_product`,
- pouze příznaků dostupných záznamů v `ps_payment_cc` a `ps_paypal_order`.

Reader nikdy neprovádí `INSERT`, `UPDATE`, `DELETE`, DDL ani zápis do legacy databáze.

## Výběr řízení a záloha

Položka s `product_id=91173` je záloha a před výběrem řízení se vždy vyloučí. Po vyloučení musí zbýt právě jedna položka:

- nula položek → `STEERING_ITEM_MISSING`,
- více položek → `STEERING_ITEM_AMBIGUOUS`,
- právě jedna položka → její reference a název tvoří produktový snapshot kandidáta.

Importér žádnou položku nevybírá odhadem.

## Úhrada

`total_paid` není samostatným důkazem úhrady. Kandidát porovnává:

- `o.valid`,
- poslední stav objednávky,
- pokrytí `total_paid_real >= total_paid`,
- přítomnost karetního záznamu,
- přítomnost PayPal stavu.

Aktuálně diagnostikovaný stav `2` je `Payment accepted`, ale produkční import zůstává blokován, dokud není celé mapování explicitně potvrzené. Nesoulad `valid` a posledního stavu vrací `PAYMENT_AMBIGUOUS`; částka sama tento výsledek nezmění.

## Potvrzené mapování `schvaleno`

Vlastník 2026-11-12 potvrdil podle produkčních `update.php`, `objednavky.php` a `offer_approval_service.php`:

- `schvaleno=0` – objednávka dosud není zařazena; kandidát je odmítnut kódem `ORDER_NOT_CLASSIFIED`,
- `schvaleno=1` – skladová repase; mapuje se na `OrderType::STOCK_REBUILD`,
- `schvaleno=2` – zakázková repase; mapuje se na `OrderType::CUSTOM_REBUILD` a společný scénář `WorkflowScenario::CUSTOM_REBUILD`.

Samotné `schvaleno=1` neurčuje, zda jde o `STOCK_DECIN`, nebo `STOCK_POLAND`. Skladový workflow proto stále vyžaduje další explicitně potvrzenou klasifikaci.

`catalog_repair` je podtyp zakázkové repase „na vlastním řízení“ a stejně jako `custom_repair` používá `schvaleno=2`. Proto se hlavní zakázková větev vytvořit může, ale `repair_subtype` zůstává `null` a dry-run hlásí `REPAIR_SUBTYPE_UNCONFIRMED`, dokud nebude nalezen jednoznačný zdroj podtypu.

## Dopravci

Živé anonymizované ověření potvrdilo:

- `id_carrier=19` → `Osobní odběr v Děčíně`,
- `id_carrier=20` → `Osobní odběr v Děčíně`.

Oba kódy se proto mapují na `PERSONAL_PICKUP`. Další dopravci musí být přidáni explicitní ověřenou konfigurací.

## Klasifikace produktu

Hlavní typ zakázky se mapuje z potvrzeného `schvaleno`. Technický typ a routing se nadále nedoplňují z názvu produktu, kategorie, `shop` ani jinou heuristikou. Skladový scénář Děčín/Polsko se rovněž nesmí odvodit ze `schvaleno` samotného. Budoucí ostrý import vyžaduje explicitní mapu zbývajících atributů podle přesného produktového kódu v `LEGACY_SHOP_PRODUCT_CLASSIFICATIONS_JSON` a současně `LEGACY_SHOP_MAPPING_VERIFIED=true`.

Dokud mapa chybí, dry-run vrací:

- `TECHNICAL_TYPE_UNCONFIRMED`,
- `ROUTING_UNCONFIRMED`,
- u skladové repase `WORKFLOW_SCENARIO_UNCONFIRMED`.

`ORDER_TYPE_UNCONFIRMED` zůstává pouze pro nezařazenou nebo neznámou hodnotu `schvaleno`.

## Kompatibilita starého MySQL

`LegacySchemaInspector` nepoužívá Laravel Schema introspekci ani `information_schema.columns.generation_expression`:

- MySQL/MariaDB: `SHOW TABLES`, `SHOW COLUMNS`, `SELECT VERSION()`,
- SQLite fixture: `sqlite_master`, `PRAGMA table_info`, `sqlite_version()`.

Živá diagnostika proběhla proti MariaDB `10.1.48-MariaDB-0ubuntu0.18.04.1`, nalezla 206 tabulek a potvrdila read-only granty.

## Dry-run

Pro přesný bezpečný běh lze použít pouze:

```bash
php artisan legacy-shop:import --dry-run --order-id=<source-id> --limit=1
```

`--order-id` je při zápisovém běhu odmítnuto. Dry-run sestaví kanonického kandidáta, používá pouze anonymní otisk objednávky a vrací kódy validačních chyb a důkazních příznaků. Nezapisuje objednávku, integrační event ani checkpoint.

Opakovaný živý dry-run nad kandidátem `d0b1b3ae75b2` po potvrzení `schvaleno` již nehlásí neznámý hlavní typ. Kandidát má `schvaleno=1`, takže je rozpoznán jako skladová repase; bezpečně zůstávají `TECHNICAL_TYPE_UNCONFIRMED`, `ROUTING_UNCONFIRMED` a `WORKFLOW_SCENARIO_UNCONFIRMED`. Počty portálových objednávek, eventů a checkpointů zůstaly před i po běhu `0|0|0`.

## Testovací pokrytí

SQLite fixture modeluje normalizované tabulky PrestaShopu. Testy pokrývají:

- běžnou objednávku bez `ps_poptavky`,
- oddělené zdrojové a externí číslo,
- vyloučení zálohové položky,
- chybějící a vícenásobné řízení,
- nejasnou platbu i při pokryté částce,
- mapování `schvaleno=1` na skladovou a `schvaleno=2` na zakázkovou repasi,
- odmítnutí `schvaleno=0`,
- neznámý podtyp `custom_repair` versus `catalog_repair`,
- idempotentní import při explicitně ověřené testovací klasifikaci,
- nulové portálové zápisy při dry-runu,
- introspekci starého MySQL pouze přes `SHOW` a `SELECT VERSION()`.

## Stop bod

`LEGACY_SHOP_IMPORT_ENABLED=false` a `LEGACY_SHOP_MAPPING_VERIFIED=false` zůstávají v produkci beze změny. Nebyl spuštěn ostrý import, scheduler ani Fáze 2.
