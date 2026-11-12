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

## D-003: Fáze 1A je pouze datové jádro

- Datum: 2026-11-12
- Stav: přijato podle explicitního zadání vlastníka
- Rozhodnutí: Fáze 1A obsahuje doménové tabulky a modely, továrnu počátečních větví a read-only dashboard/detail. Legacy připojení, mapování, scheduler a import jsou mimo 1A.
- Dopad: `LEGACY_SHOP_IMPORT_ENABLED=false`; testovací data vznikají výhradně přes factories a testy.

## D-004: Odpovědnost zákazníka není falešná organizace

- Datum: 2026-11-12
- Stav: přijato podle MASTER v17
- Rozhodnutí: `process_threads` ukládá stabilní `responsible_party` (`CUSTOMER`, `AUTOKLUBY`, `POLAND`, `SYSTEM`) a volitelnou vazbu na organizaci. Čekání na zákazníka tedy nevytváří fiktivní organizaci.
- Dopad: „Kdo je na tahu“ lze odvodit i pro zákaznické kroky a row-level oprávnění Polska se stále opírá o skutečnou organizaci.

## D-005: Scénář skladové zakázky musí být explicitní

- Datum: 2026-11-12
- Stav: přijato pro Fázi 1A
- Rozhodnutí: Továrna počátečních větví přijímá explicitní scénář `STOCK_DECIN` nebo `STOCK_POLAND`; dostupnost skladu se nehádá a nečte z produkčních systémů.
- Dopad: Budoucí import/Pohoda adaptér musí scénář určit z ověřených dat před zavoláním továrny.

## D-006: Legacy mapování je pouze explicitní a ověřené

- Datum: 2026-11-12
- Stav: přijato pro Fázi 1B
- Rozhodnutí: Schéma a významy legacy e-shopu se nesmí odhadovat. Tabulka, sloupce, uhrazené stavy a všechny procesní mapy se konfigurují přes `.env`; import je odmítne, dokud není mapování ručně označeno jako ověřené.
- Dopad: Diagnostiku lze použít k získání anonymizovaných podkladů, ale neznámé hodnoty vytvoří viditelnou integrační chybu místo tichého doplnění.

## D-007: Import je bezpečně vypnutý a bez scheduleru

- Datum: 2026-11-12
- Stav: přijato pro Fázi 1B
- Rozhodnutí: Zápisový import vyžaduje `LEGACY_SHOP_IMPORT_ENABLED=true`, povinnou startovní hranici a ověřené mapování. Fáze 1B nezapíná produkční připojení, cron ani scheduler a končí před živým ověřením.
- Dopad: Kód lze ověřit na fixture databázi a později spustit v dry-run režimu, ale v aktuálním výchozím nastavení nemůže vytvářet objednávky.

## D-008: Portál nepřebírá zákaznické schvalování

- Datum: 2026-11-12
- Stav: potvrzeno provozovatelem pro Fázi 1D
- Rozhodnutí: Původní `objednavky.php` nadále spravuje vztah zákazník–e-shop, platbu a klasifikaci. Nový portál je most AUTOKLUBY CZ–Polsko. `schvaleno=0` čeká, `1` znamená `STOCK_REBUILD` a `2` znamená `CUSTOM_REBUILD`.
- Dopad: Portál nevytváří zákaznický schvalovací krok. Čekající nula se znovu kontroluje a checkpoint ji nesmí přeskočit.

## D-009: Autoritou úhrady je historický stav 2

- Datum: 2026-11-12
- Stav: potvrzeno provozovatelem pro Fázi 1D
- Rozhodnutí: Objednávka je uhrazena právě tehdy, když její `ps_order_history` obsahuje alespoň jeden záznam `id_order_state=2` (`Payment accepted`). Nemusí jít o poslední stav.
- Dopad: `total_paid`, `total_paid_real`, `ps_orders.valid`, poslední stav a pomocné platební tabulky jsou pouze diagnostické signály. Bez historického stavu 2 importér vrátí `PAYMENT_NOT_ACCEPTED`.

## D-010: Technický typ a routing spravuje produktový registr portálu

- Datum: 2026-11-12
- Stav: přijato pro Fázi 1D
- Rozhodnutí: Legacy schéma neposkytuje jednoznačný zdroj `WORM/HYDRAULIC/EPS` ani `KRAFT/ARGO`. Portál je eviduje podle přesné `product_reference`, včetně stavu ověření a osoby/času potvrzení. Z názvu ani kódu produktu se nic neodhaduje.
- Dopad: Chybějící nebo neověřená konfigurace vrací `PRODUCT_CONFIGURATION_REQUIRED` ve viditelné integrační frontě AUTOKLUBY a nesmí pokračovat do Polska.

## D-011: Skladový scénář určuje Pohoda

- Datum: 2026-11-12
- Stav: potvrzeno provozovatelem pro Fázi 1D
- Rozhodnutí: PrestaShop nerozhoduje, zda je skladový kus v Děčíně nebo v Polsku. Autoritou je aktuální Pohoda snapshot.
- Dopad: Bez snapshotu vzniká pouze AUTOKLUBY stav `PENDING_STOCK_CHECK`; polská větev se nevytvoří ani nezobrazí.

## D-012: `ps_product.omacka` je autoritou technického typu

- Datum: 2026-11-12
- Stav: potvrzeno provozovatelem pro Fázi 1E; nahrazuje předpoklad D-010, že legacy zdroj neexistuje
- Rozhodnutí: Po trimu a bez ohledu na velikost písmen se `EPS/EPS_H/EPS_EL` mapují na `EPS`, `HYD/HYD_H/HYD_V/HYD_HV` na `HYDRAULIC` a `snek` na `WORM`. `Mech_el`, `Mech_bez`, prázdná, `NULL` a jiné hodnoty zůstávají neznámé.
- Dopad: Výchozí routing je `WORM→KRAFT`, `HYDRAULIC/EPS→ARGO`. Raw `omacka` zůstává v auditu. Ověřený produktový registr je explicitní oprava/override, nikoli povinný zdroj pro známé hodnoty.

## D-013: Zakázkový podtyp používá pouze schválenou nabídku

- Datum: 2026-11-12
- Stav: potvrzeno referenčními `objednavky.php`, `update.php` a `offer_approval_service.php`
- Rozhodnutí: `schvaleno=2` je standardně `custom_repair`. Pouze použitý a VIN-schválený záznam `ps_offer_vin_token` přímo spojený přes `id_order` s `offer_mode=catalog_repair` nastaví `catalog_repair`. Konfliktní kvalifikované režimy se nesmí řešit volbou „posledního“ záznamu.
- Dopad: Konflikt vrací `REPAIR_SUBTYPE_AMBIGUOUS`; chybějící nabídka bezpečně ponechá standardní `custom_repair`.

## D-014: Automatická mapa předání je uzavřený allowlist

- Datum: 2026-11-12
- Stav: potvrzeno provozovatelem a referenčním kódem pro Fázi 1E
- Rozhodnutí: `18→GLS`, `19/20→PERSONAL_PICKUP`. Žádný jiný carrier se automaticky nemapuje na GLS.
- Dopad: Ostatní hodnoty končí `DELIVERY_MAPPING_REQUIRED`. Legacy kód výslovně potvrzuje, že osobní odběr tvoří pouze 19/20; živý číselník potvrzuje název GLS pro 18.
