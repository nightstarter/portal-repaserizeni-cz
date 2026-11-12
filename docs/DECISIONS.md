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
- Stav: nahrazeno rozhodnutím D-024 ve Fázi 2C
- Rozhodnutí: Ve Fázi 1A používala testovací továrna explicitní scénář `STOCK_DECIN` nebo `STOCK_POLAND`; dostupnost se nikdy nehádala.
- Dopad: Fáze 2C odstranila tuto obcházející vstupní cestu. Import vytváří pouze `PENDING_STOCK_CHECK` a výsledek materializuje výhradně důvěryhodný resolver přes `StockBranchDecisionService`.

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

## D-015: `RECEIVED_AK` se rozlišuje vstupní událostí

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2A
- Rozhodnutí: První a zpětný příjem používají stejný stabilní stav `RECEIVED_AK`. Další povolenou akci určuje poslední neměnná událost (`CONFIRM_AK_RECEIPT` nebo `CONFIRM_AK_RETURN_RECEIPT`) uvnitř zamčené větve.
- Dopad: Nevznikají alternativní veřejné kódy. Override přímo do `RECEIVED_AK` neodemkne běžný navazující krok a musí se dořešit dalším auditovaným override.

## D-016: Čekající zakázkovou repasu obsluhuje AUTOKLUBY

- Datum: 2026-11-12
- Stav: přijato podle explicitního zadání Fáze 2A
- Rozhodnutí: Počáteční `CUSTOM_REBUILD` větev ve stavu `WAITING_CUSTOMER_ITEM` má odpovědnost `AUTOKLUBY` a vazbu na organizaci AUTOKLUBY, protože její další systémovou akcí je příjem AUTOKLUBY. Fyzický kus přitom zůstává v lokalitě `CUSTOMER`.
- Dopad: Jde o zpřesnění D-004 pro tuto konkrétní větev; nevytváří se falešná zákaznická organizace a akční fronta ukazuje na skutečného provozního vykonavatele.

## D-017: 700 Kč za axiální táhla je odvozená nefinanční hodnota

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2A
- Rozhodnutí: Na fyzickém kusu se ukládá pouze příznak `axial_rods`. Doménová metoda z něj odvozuje 700 Kč nebo 0 Kč.
- Dopad: Fáze 2A nevytváří finanční záznam, doklad ani platbu. Budoucí finanční workflow může hodnotu převzít explicitním doménovým krokem.

## D-018: Idempotence workflow patří k události větve

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2A
- Rozhodnutí: Idempotency key je unikátní v rámci procesní větve a událost ukládá hash akce, aktéra, dat a očekávané verze.
- Dopad: Shodné opakování vrací původní událost. Opětovné použití klíče pro jiný příkaz je konflikt; nevznikne druhá událost ani audit.

## D-019: Čtecí projekce a zápis používají společný guard

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2B
- Rozhodnutí: Povolené UI akce odvozuje `CustomRebuildPresentation` přes stejný `CustomRebuildActionGuard`, který před zápisem používá `CustomRebuildWorkflow`.
- Dopad: Kontroler ani Blade šablona nemají paralelní tabulku přechodů. Skryté tlačítko nelze zpřístupnit změnou HTTP payloadu a změna doménových pravidel se promítne do čtení i zápisu.

## D-020: Polské oprávnění vzniká historií přiřazení CUSTOM_REBUILD

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2B
- Rozhodnutí: Polsko smí číst jen zakázky s `CUSTOM_REBUILD` větví, která má nebo historicky měla polskou odpovědnou organizaci. Přístup se vynucuje společným query scopem a policy.
- Dopad: Seznam, přímý detail i QR používají stejnou row-level hranici. Polské projekce nenačítají zákaznický vztah a nezobrazují kontaktní PII ani interní poznámky AUTOKLUBY.

## D-021: QR token identifikuje kus, ale neuděluje oprávnění

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2B
- Rozhodnutí: Veřejná cesta používá bezpečný náhodný token místo sekvenčního ID, vždy vyžaduje přihlášení a následně standardní oprávnění k zakázce.
- Dopad: Po loginu se uživatel vrátí na QR detail. Neplatný i existující cizí token odpovídá shodným `404`, takže nelze ověřovat existenci zakázky.

## D-022: Polská časová osa je explicitní bezpečná projekce

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2B
- Rozhodnutí: Časová osa se skládá z neměnných `thread_events`; metadata se zveřejňují podle allowlistu. Polsko nevidí admin důvody, interní auditní hodnoty, volné interní poznámky ani zákaznické PII.
- Dopad: Přidání nového metadata klíče jej samo nezveřejní. AUTOKLUBY může vidět širší provozní kontext a informaci, že událost má audit.

## D-023: Oba typy repase používají společný transakční executor

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2C
- Rozhodnutí: `CUSTOM_REBUILD` i skladové větve používají `ThreadWorkflowEngine` pro zamykání, idempotenci, optimistic concurrency, event, audit a přepočet uzavření. Stavové automaty dodávají pouze doménová pravidla.
- Dopad: Nevzniká druhá infrastruktura přechodů. Regresní testy celé zakázkové repase ověřují, že extrakce společného executor nezměnila chování Fáze 2A.

## D-024: Neprůkazná skladová data nematerializují workflow

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2C
- Rozhodnutí: Skladové rozhodnutí přijímá portál pouze přes `ResolvesStockAvailability`. `UNKNOWN` a `STALE` nejsou rozhodnutí a ponechají `PENDING_STOCK_CHECK`. Výchozí implementace bezpečně vrací `UNKNOWN`; fixture resolver existuje pouze v testech.
- Dopad: Portál se nepřipojuje k Pohodě, neodhaduje dostupnost z PrestaShopu a nevytváří předčasné polské oprávnění. Budoucí Pohoda bridge implementuje stejný kontrakt.

## D-025: Skladové větve a fyzické role jsou databázově instancované

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2C
- Rozhodnutí: `process_threads.type_instance` a `items.role_instance` mají výchozí hodnotu 1 a tvoří s objednávkou a typem/rolí unikátní klíč.
- Dopad: Současné singleton větve a kusy nelze vytvořit dvakrát ani při souběhu. Budoucí opakovatelné cykly mohou použít vyšší instanci bez oslabení ochrany stávajících dat.

## D-026: Uzavření objednávky je odvozené ze všech povinných větví

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2C
- Rozhodnutí: `OrderCompletionService` nastaví `closed_at` pouze tehdy, když žádná procesní větev objednávky nemá `completed_at=NULL`.
- Dopad: Dokončení zákaznického `STOCK_REQUEST` ani doplnění skladu nezavře objednávku s aktivním `CORE_RETURN`. Stejná služba zachovává dosavadní uzavření jednovětvého `CUSTOM_REBUILD`.
