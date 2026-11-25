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

## D-027: Dashboard skladové repase je projekce větví

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2D
- Rozhodnutí: Jeden skladový řádek reprezentuje jednu viditelnou procesní větev. Fronty jsou odvozené klasifikace stejné větve a nevytvářejí další globální status objednávky.
- Dopad: Jedno `order_no` se může v přehledu objevit vícekrát s různou odpovědností, ETA a další akcí. Uživatel vidí skutečný paralelní průběh místo sloučeného stavu.

## D-028: QR oprávnění se kontroluje také na úrovni fyzického kusu

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2D
- Rozhodnutí: Role fyzického kusu určuje související větev a QR controller vyžaduje, aby polský uživatel měl přístup právě k této větvi, nikoli jen k některé větvi stejné objednávky.
- Dopad: Přístup k `STOCK_REPLENISHMENT` nezpřístupní QR zákaznického `STOCK_ITEM` ani čekajícího `CORE_RETURN`. Cizí a neplatný token zůstávají nerozlišitelné odpovědí 404.

## D-029: ETA je platná pouze jako budoucí provozní termín

- Datum: 2026-11-12
- Stav: přijato pro Fázi 2D
- Rozhodnutí: `SET_PL_STOCK_ETA` validuje datum a čas v doménové command službě a odmítá minulost. HTML `min` je pouze uživatelská pomůcka.
- Dopad: Přímý HTTP požadavek nemůže obejít pravidlo a každá platná změna ETA vytváří novou událost a audit.

## D-030: Fáze 2 je přijata s explicitním deployment gate

- Datum: 2026-11-12
- Stav: přijato ve Fázi 2E
- Rozhodnutí: Automatizovaná funkční a bezpečnostní akceptace Fází 1–2 je zelená. Produkční otevření zápisů Fáze 2C/2D je však podmíněno zálohou, nastavením `APP_ENV=production` a aplikací jediné auditované migrace `2026_11_12_000005_add_phase_2c_domain_guards` v servisním okně.
- Dopad: Akceptace sama nemění produkční data ani konfiguraci. Import, scheduler a Pohoda bridge zůstávají vypnuté a Fáze 3 nezačíná.

## D-031: Aktivní zásilka je výlučná rezervace na fyzickém kusu

- Datum: 2026-11-12
- Stav: přijato pro Fázi 3A
- Rozhodnutí: Historie přeprav zůstává v `shipment_items`, zatímco jedinou aktivní rezervaci nese nullable FK `items.active_shipment_id`. Rezervace se uvolní pouze při odebrání z `PREPARED` nebo po atomickém `DELIVERED`; `TRANSPORT_ERROR` ji zachovává.
- Dopad: Jeden item nemůže v modelu současně odkazovat na dvě aktivní zásilky a historie jeho dřívějších přeprav se po uvolnění rezervace nemaže.

## D-032: ShipmentService koordinuje existující workflow enginy

- Datum: 2026-11-12
- Stav: přijato pro Fázi 3A
- Rozhodnutí: Hromadné odeslání a příjem předem zamknou a validují všechny položky, potom uvnitř jedné vnější transakce volají stávající `CustomRebuildWorkflow` nebo `StockRebuildWorkflow`. Klient neposílá thread action ani cílový stav.
- Dopad: Thread eventy, audity, idempotence a pravidla stavových automatů nejsou implementované podruhé. Selhání jediného threadu vrátí stav zásilky, všechny přechody i rezervace.

## D-033: Idempotence zásilek je událost s globálním klíčem

- Datum: 2026-11-12
- Stav: přijato pro Fázi 3A
- Rozhodnutí: `shipment_events.idempotency_key` je globálně unikátní. Request hash obsahuje shipment command, aktéra, payload, očekávanou verzi a kontext itemu/threadu; interní thread klíč se deterministicky odvodí z shipment commandu.
- Dopad: Replay stejného commandu vrací původní výsledek a jiný payload se stejným klíčem je konflikt. Zásilka zároveň používá `lock_version` a row locks proti dvojímu odeslání, příjmu nebo souběžné editaci obsahu.

## D-034: Předvalidace velké zásilky načítá vztahy po množinách

- Datum: 2026-11-13
- Stav: přijato ve Fázi 3C
- Rozhodnutí: `ShipmentService` zamkne itemy a thready deterministicky a potřebné objednávky a thread eventy načte dávkově před per-item validací. Samotné zápisy thread eventů a auditů zůstávají záměrně po jednotlivých větvích uvnitř jedné transakce.
- Dopad: Test 100 položek snížil validační průchod z 206 SELECTů pod pevnou mez 10 a zároveň dokládá úplný rollback při chybě posledního itemu. Lineární počet nutných doménových zápisů se neskrývá jako N+1 čtení.

## D-035: Fáze 3 je přijata bez další produkční migrace

- Datum: 2026-11-13
- Stav: přijato ve Fázi 3C
- Rozhodnutí: Automatizovaná doménová, HTTP, bezpečnostní a regresní akceptace Fáze 3 je zelená. Produkční `migrate:status` ukazuje 000001–000006 jako aplikované a přesný seznam pending migrací je prázdný. Již aplikovaný soubor 000006 zůstává neměnný a jeho hash odpovídá incident dokumentaci.
- Dopad: Nasazení je kódové a nepovoluje spuštění migrace, importu, Pohoda bridge ani scheduleru. Browser smoke test zůstává povinnou ruční deployment branou, protože v relaci 3C nebyl browser nástroj dostupný.

## D-036: Zákaznický snapshot a dodavatelská záloha jsou oddělené domény

- Datum: 2026-11-18
- Stav: přijato pro Fázi 4A
- Rozhodnutí: `CUSTOMER_DEPOSIT_SNAPSHOT` z legacy e-shopu je pouze reference pro AUTOKLUBY. `SUPPLIER_DEPOSIT_ORIGINAL` vzniká výhradně explicitním polským zápisem s vlastní částkou a ISO měnou; doména odmítá zákaznický snapshot jako zdroj.
- Dopad: Portál zákaznickou zálohu ve 4A nevrací a Polsku ji nevydává za jeho dodavatelskou pohledávku.

## D-037: Dodavatelské finance jsou append-only ledger

- Datum: 2026-11-18
- Stav: přijato pro Fázi 4A
- Rozhodnutí: Finanční řádky se nepřepisují. Každý je navázaný na order, CORE_RETURN, thread event, audit, actor, organizaci, idempotency key a request hash. Oprava používá nový `CORRECTION` nebo `REVERSAL` pouze od `AK_ADMIN` s důvodem.
- Dopad: Historické rozhodnutí zůstává dohledatelné a transakce thread/event/audit/finance se vrací jako celek.

## D-038: Bez-zálohová větev vyžaduje kladný důkaz absence

- Datum: 2026-11-18
- Stav: přijato pro Fázi 4A
- Rozhodnutí: `CONFIRM_NO_SUPPLIER_DEPOSIT` vyžaduje předchozí `SUPPLIER_DEPOSIT_NOT_CHARGED`. Chybějící nebo nullable originál není důkaz.
- Dopad: Větev nevytváří falešný dobropis, zapíše vypořádací událost a jako jediný finanční výsledek 4A dokončí CORE_RETURN.

## D-039: CORE_RETURN rozšiřuje, ale neduplikuje ShipmentService

- Datum: 2026-11-18
- Stav: přijato pro Fázi 4A
- Rozhodnutí: `AK_TO_PL` přijímá `CORE_RETURN/CORE_RECEIVED_AK` pro GLS i svoz. ShipmentService odvodí `SEND_TO_POLAND` a `CONFIRM_CORE_RECEIPT_PL` přes CoreReturnWorkflow ve stejné dávkové transakci.
- Dopad: Aktivní rezervace, fyzická lokalita, idempotence, lock version a rollback celé zásilky zůstávají společné s Fází 3. `PL_TO_AK` vratku v této části odmítá.

## D-040: Doklady jsou soukromé objekty, nikoli veřejné soubory

- Datum: 2026-11-18
- Stav: přijato pro Fázi 4B
- Rozhodnutí: Dodavatelská faktura a dobropis používají náhodnou interní cestu na private disku a náhodný route token. Download vždy prochází policy a stejnou 404 odpovědí skrývá neexistující i nepovolený dokument.
- Dopad: Uživatelský filename neurčuje storage cestu, soubory nemají veřejnou URL a odpověď vynucuje attachment a `nosniff`.

## D-041: Dokumentová transakce kompenzuje filesystem

- Datum: 2026-11-18
- Stav: přijato pro Fázi 4B
- Rozhodnutí: Soubor se uloží až po úplné validaci a před DB transakcí; selhání DB jej kompenzačně smaže. Selhání storage zastaví operaci před eventem, auditem a ledgerem.
- Dopad: Nevznikají finance bez dokladu ani běžné osiřelé soubory. Metadata, workflow, audit a finance jsou atomické v databázi.

## D-042: Oprava dokladu je nová verze a finanční reverz

- Datum: 2026-11-18
- Stav: přijato pro Fázi 4B
- Rozhodnutí: Faktury ani dobropisy se nepřepisují. Aktivní verze se s důvodem invaliduje, nová ji odkazuje a finanční dopad se zaznamená append-only reverzem a novými entries.
- Dopad: Jedna větev má nejvýše jeden aktivní doklad daného významu, ale původní metadata, soubor, actor a audit zůstávají dohledatelné.

## D-043: Reverzovaný finanční údaj není účinným obchodním důkazem

- Datum: 2026-11-18
- Stav: přijato při Fázi 4C
- Rozhodnutí: Append-only ledger zachová původní řádek, ale doménové dotazy pro rozhodnutí a UI vyloučí entry, na kterou míří `REVERSAL`. Při další opravě faktury se reverzují pouze finance aktuální verze, nikoli staré reverzní řádky.
- Dopad: Oprava faktury „se zálohou ↔ bez zálohy“ nemůže současně povolit obě vypořádací větve. Historie a audit zůstávají úplné.

## D-044: Fáze 4 je automatizovaně přijata s deployment branami

- Datum: 2026-11-18
- Stav: přijato při Fázi 4C
- Rozhodnutí: Doména, dokumenty, UI a regrese prošly 302 testy / 1191 assertions na SQLite `:memory:`. Produkční aktivace vyžaduje konzistentní DB+filesystem zálohu, private storage, PHP limity 10M/12M, schválené migrace 000007/000008 a ruční browser smoke test.
- Dopad: Akceptace sama nemění produkci, neopravňuje push ani migraci a nezahajuje Fázi 5.

## D-045: Přecenění zůstává v externím systému

- Datum: 2026-11-20
- Stav: přijato vlastníkem pro Fázi 7C
- Rozhodnutí: `PRODUCT_REPRICING = DEFERRED_EXTERNAL_SYSTEM`. Portál ani HPSERVER bridge nezapisují `SKz.NakupC`, `SKz.NakupDPH`, `SKz.CMKodNC`, `ps_product.wholesale_price` ani jinou cenu Pohody nebo e-shopu. Produkční bridge command whitelist zůstává prázdný, konektor používá pouze read-only SQL účet a existující objednávky se nikdy zpětně nepřecení.
- Dopad: Nevzniká záložka Přecenění, cenový endpoint ani cenový command. TODO pro případné sjednocení: nové samostatné zadání musí určit cílový systém, autoritativní měnu a DPH, oprávnění, schvalovací workflow, audit, idempotenci a bezpečný rollback; bez něj zůstává přecenění mimo portál.

## D-046: Nezměněný sklad se potvrzuje fingerprintem

- Datum: 2026-11-20
- Stav: přijato pro Fázi 7C
- Rozhodnutí: První nebo změněný RR dataset vytvoří plný chunkovaný snapshot. Stejný deterministický fingerprint následně vytvoří pouze append-only potvrzení vůči nejnovějšímu použitelnému COMPLETE/PARTIAL runu; množství zůstává vždy v base runu.
- Dopad: Potvrzení obnoví čas ověření bez tisíců nových položek, nemění PARTIAL na COMPLETE a při konfliktu nebo ztrátě lokálního stavu konektor bezpečně odešle nový plný snapshot.

## D-047: PHP 7.4.33 a Windows Server 2012 R2 jsou přijaté provozní riziko

- Datum: 2026-11-20
- Stav: přijato pro Fázi 8A
- Rozhodnutí: Runtime a OS HPSERVER konektoru se v 8A nemění. Preflight je vždy uvádí jako dokumentovaný `WARN`, nikoli `ERROR`; konektor zůstává kompatibilní s PHP CLI 7.4.33.
- Dopad: Upgrade PHP, Windows, WAMPu, intranetu, SQL Serveru nebo Pohody není podmínkou akceptace 8A ani skrytým úkolem jiné podfáze bez nového rozhodnutí vlastníka.

## D-048: Provozní preflight je read-only a Task Scheduler je ruční kontrola

- Datum: 2026-11-20
- Stav: přijato pro Fázi 8A
- Rozhodnutí: `ops:preflight` a AK_ADMIN stránka používají tutéž read-only službu. Neopravují stav, neaplikují migrace, neodesílají SMTP/snapshot a nespouštějí connector command. Protože portál nemá důvěryhodný Task Scheduler heartbeat, stav Scheduleru je explicitní `WARN` s ručním checklistem.
- Dopad: Exit code je nenulový pouze při `ERROR`; `WARN` umožní provoz s viditelným rizikem. JSON nikdy nezveřejní secret, DSN, `.env`, exception detail ani PII.

## D-049: READ_ONLY nesmí měnit ani unread doménový stav

- Datum: 2026-11-20
- Stav: přijato pro Fázi 8A
- Rozhodnutí: READ_ONLY může číst objekty ve svém organizačním scope, ale nesmí vytvářet message read marker ani jiný doménový zápis. GET diskuse je pro tuto roli skutečně read-only a přímý mark-read POST je odmítnut.
- Dopad: Vlastní změna hesla/jazyka zůstává bezpečnostní/uživatelská preference; všechny workflow, finance, dokumenty, zásilky, diskuse, unread, WARRANTY, BUYBACK a admin operace jsou bez zápisu.

## D-050: COMPLETE je vlastnost ověřeného manifestu, ne názvu souboru

- Datum: 2026-11-20
- Stav: přijato pro implementaci Fáze 8B
- Rozhodnutí: Záloha je `COMPLETE` jen tehdy, když manifest formátu 1 obsahuje všechny povinné komponenty, bezpečná metadata, počty kritických tabulek a každá deklarovaná část existuje se shodnou velikostí a SHA-256. Přípona, název adresáře ani testovací fixture nejsou důkaz produkční zálohy.
- Dopad: Neplatný, neúplný, traversal/symlinkový nebo checksumově změněný archiv se odmítne. Manifest nesmí obsahovat hesla, APP_KEY, HMAC/SMTP secret, celé DSN ani PII.

## D-051: Obnova má samostatnou izolovanou DB a storage hranici

- Datum: 2026-11-20
- Stav: přijato pro implementaci Fáze 8B
- Rozhodnutí: Portál neprovádí produkční DB restore. `backup:restore-check` běží jen v `testing` nebo explicitním `restore-test`, odmítá produkční DB identitu a private storage a kopíruje soubory jen do nového prázdného canonical cíle mimo chráněné cesty. Databázový import do nové DB provede provozovatel před kontrolou standardním klientem.
- Dopad: Kontrola porovná migrace, kritické počty, FK, unikátní indexy, DB reference dokumentů, SHA-256 a orphan policy. Scheduler, SMTP, import, bridge a přecenění musí zůstat vypnuté. Nástroj po úspěchu sandbox nemaže; při chybě uklidí pouze vlastní soubory.

## D-052: RPO a RTO určuje vlastník až podle reálného měření

- Datum: 2026-11-20; potvrzeno vlastníkem 2026-11-25
- Stav: přijato
- Rozhodnutí: Doporučená frekvence a retence nejsou smluvní cíle. Vlastník výslovně potvrzuje RPO 24 hodin a RTO 8 hodin; testovací fixture se nadále nesmí vydávat za důkaz jejich dosažení.
- Dopad: Denní backup a restore cvičení se vyhodnocují proti potvrzeným hodnotám. Fáze 8B zůstává otevřená pouze kvůli zbývající off-server kontrole a provozním měřením. Automatické mazání starých záloh není aktivováno.

## D-053: Produkční COMPLETE balíček vzniká pouze atomickým create workflow

- Datum: 2026-11-20
- Stav: přijato pro opravu Fáze 8B
- Rozhodnutí: `backup:create` v produkci automaticky aktivuje Laravel maintenance, vyčká konfigurovaný drain interval a v sourozeneckém privátním stagingu vytvoří transakční MariaDB dump, TAR private storage a sanitizovaná metadata. Všechny části se nejprve ověří proti neveřejnému nekompletnímu manifestu; COMPLETE manifest se zapíše atomicky až poté, celý staging znovu projde stejným verifierem a finální adresář vznikne až rename ověřeného stagingu.
- Dopad: DB heslo je pouze v dočasném mode-0600 option file a není v argv. Chyba nikdy nezveřejní COMPLETE cíl a uklidí jen vlastní staging. Maintenance se ve `finally` vrací do původního stavu. Externí CLI writery musí provozovatel během okna vyloučit; příkaz nemění cron ani Task Scheduler.

## D-054: Dump chyby mají bezpečnou klasifikaci a logical objects se zálohují podmíněně

- Datum: 2026-11-21
- Stav: přijato pro opravu Fáze 8B
- Rozhodnutí: `backup:create` nezveřejňuje raw stderr, přihlašovací údaje, host ani DSN, ale vrací stabilní bezpečný kód, fázi a exit code dump klienta. `backup:diagnose` provádí pouze schema-only probe ve vlastním dočasném sandboxu. Přepínače pro triggery, routines a events se použijí jen tehdy, pokud odpovídající objekty v portálové DB existují; existující objekt se nikdy potichu nevynechá.
- Dopad: `--defaults-extra-file` je první volba klienta, heslo zůstává v dočasném souboru s mode 0600 a stdout se streamuje do chráněného `database.sql`. Základní dump používá `--single-transaction`, `--quick`, `--skip-lock-tables` a MariaDB-kompatibilní `--no-tablespaces`. Nedostatečné oprávnění pro skutečně existující logical objects je bezpečný `ERROR`, nikoli důvod automaticky rozšířit práva aplikačního účtu.

## D-055: Bezpečná chyba backup:create se zachovává přes všechny fáze

- Datum: 2026-11-21
- Stav: přijato pro opravu Fáze 8B
- Rozhodnutí: Creator převádí každou chybu na jednotný bezpečný objekt s `status`, `code`, `phase`, volitelným `process_exit_code` a `recommendation`; Artisan renderer už nerozpoznává pouze dump výjimky. Selhání maintenance cleanupu nesmí přepsat dřívější klasifikaci. Diagnostika doplňuje izolovaný data-path probe omezený na nejvýše jeden řádek technické tabulky `migrations`.
- Dopad: Provozovatel bezpečně rozliší dump, private archiv, metadata, manifest, verify, finalize a maintenance bez zveřejnění raw stderr, argv nebo DB identity. Fáze 8B zůstává nedokončená a nový produkční create se smí provést až po nasazení a úspěšném diagnose.

## D-056: Candidate manifest používá interní typed ověření a control file není doménový orphan

- Datum: 2026-11-21
- Stav: přijato pro opravu Fáze 8B
- Rozhodnutí: Neveřejný candidate má stav `INCOMPLETE` a ověřuje se pouze přes typed `BackupCandidateManifest`; veřejný verifier vždy vyžaduje `COMPLETE`. Přesné kořenové private cesty `.gitignore` a `legacy-shop-phase1c-report.json` jsou provozní artefakty, proto se nearchivují ani neporovnávají s DB referencemi.
- Dopad: Produkční příčina předchozího `VERIFY_FAILED` je odstraněna bez oslabení kontroly dokumentů. Bezpečné verifier podkódy rozlišují manifest, status, chybějící komponentu, velikost, checksum, private inventář, DB metadata, unsafe cestu a interní chybu bez zveřejnění citlivých dat.

## D-057: Private storage exclusions jsou uzavřená verzovaná politika

- Datum: 2026-11-21
- Stav: přijato pro opravu Fáze 8B
- Rozhodnutí: Aplikace vylučuje pouze exact relative paths `.gitignore` (`DEPLOYMENT_CONTROL_FILE`) a `legacy-shop-phase1c-report.json` (`GENERATED_DIAGNOSTIC_ARTIFACT`). Seznam není dostupný přes `.env`, wildcard ani provozní konfiguraci a musí být shodný v plan/create/verify/restore-check.
- Dopad: Oba zdrojové soubory zůstávají fyzicky beze změny a jsou evidovány jen jako sanitizované exclusions. Nejsou v TAR ani restore. Kopie, nested varianta, jiné JSON/report soubory a každý neznámý orphan nadále způsobí `PRIVATE_INVENTORY_MISMATCH`.

## D-058: Recovery evidence je autentizovaný sidecar, nikoli změna COMPLETE manifestu

- Datum: 2026-11-21
- Stav: přijato pro opravu Fáze 8B
- Rozhodnutí: Původní COMPLETE manifest a jeho tři komponenty jsou neměnné. Off-server potvrzení a append-only historie úspěšných restore testů se ukládají atomicky do `recovery-evidence.json`, svázaného s backup ID, SHA-256 manifestu a produkčním prostředím a chráněného Laravel `Crypt`. Tajný APP_KEY zůstává v odděleném escrow. Off-server příkaz eviduje výslovnou atestaci provozovatele po nezávislé kontrole; vzdálenou kopii sám nekontroluje.
- Dopad: Chybějící sidecar je `WARN` a nemění validitu zálohy. Neplatná integrita nebo cizí vazba je preflight `ERROR`. Restore zapisuje `SUCCESS` pouze po úplné izolované kontrole, nové testy zachovávají historii a neúspěch evidence nemění. Ruční editace manifestu ani sidecaru není podporovaná.

## D-059: Opakovaný monitoring je persistentní, ale oddělený od workflow

- Datum: 2026-11-24
- Stav: přijato pro implementaci Fáze 8C
- Rozhodnutí: Monitorovací běhy, aktivní alerty a append-only události mají vlastní tabulky a služby. Monitoring čte preflight a bezpečné agregáty, ale nemění objednávky, integrace, outbox payloady ani connector data.
- Dopad: Stejný `check_key + fingerprint` má jeden aktivní alert; resolve uvolní dedup klíč a další výskyt založí nový cyklus.

## D-060: ACK je lidské převzetí, nikoli technické uzdravení

- Datum: 2026-11-24
- Stav: přijato pro implementaci Fáze 8C
- Rozhodnutí: ACK vyžaduje AK_ADMIN, CSRF, poznámku a aktuální `row_version`. Nemění výsledek checku, occurrence ani fingerprint a nebrání eskalaci na CRITICAL.
- Dopad: RESOLVED vytváří pouze následující zdravý monitorovací výsledek. Audit a alert event uchovávají oddělené stopy.

## D-061: Alert e-mail a samotný monitoring mají oddělené feature flagy

- Datum: 2026-11-24
- Stav: přijato pro implementaci Fáze 8C
- Rozhodnutí: `OPS_MONITOR_ENABLED=false` a `OPS_ALERT_EMAIL_ENABLED=false` jsou nezávislé bezpečné výchozí hodnoty. Příjemci jsou pouze runtime konfigurace; testy používají fake mailer.
- Dopad: Monitoring lze nejprve provozovat s DB/UI/log routingem bez SMTP. Doručení je at-least-once a vzácná duplicita po pádu mezi send a potvrzením je dokumentovaný limit.

## D-062: Veřejný health endpoint je minimální readiness, nikoli diagnostika

- Datum: 2026-11-24
- Stav: přijato pro implementaci Fáze 8C
- Rozhodnutí: `/health` je rate-limitovaný a vrací pouze `OK`/200 nebo `UNAVAILABLE`/503 podle aplikace, DB a runtime storage. Pohoda, SMTP, backup a TLS monitoring jeho liveness neblokují.
- Dopad: Detail checks, historie a metadata jsou pouze v CLI a autorizovaném AK_ADMIN UI. Původní framework `/up` se nepoužívá.

## D-063: Plánovaný backup sdílí creator lock a latest stav publikuje pointerem

- Datum: 2026-11-25
- Stav: přijato pro implementaci Fáze 8B/8C
- Rozhodnutí: `backup:scheduled` je defaultně vypnutý, vytváří bezpečný UTC cíl pouze v `BACKUP_ROOT` a s ručním `backup:create` sdílí jeden cache lock. `BACKUP_LATEST_POINTER` se atomicky posune až po `COMPLETE` a úspěšném verifieru; `BACKUP_LATEST_MANIFEST` je fallback pouze pro chybějící nebo nenakonfigurovaný pointer.
- Dopad: Selhání zachová předchozí pointer a žádná cesta automaticky nepotvrdí off-server kopii ani nemaže zálohy. Scheduler používá enabled filtr a `withoutOverlapping`; monitoring čte persistentní bezpečné stavy `SUCCESS`, `FAILED`, `SKIPPED_DISABLED`, `SKIPPED_LOCKED` přes existující monitorovací sadu.
