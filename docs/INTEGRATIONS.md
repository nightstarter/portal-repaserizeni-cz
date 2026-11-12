# Integrace

Nejsou aktivní žádné produkční integrace.

## Starý e-shop

- `LEGACY_SHOP_IMPORT_ENABLED=false` je povinný výchozí stav.
- Fáze 1B obsahuje bezpečně vypnuté připojení, read-only diagnostiku a importní službu; podrobnosti jsou v `docs/PHASE_1B.md`.
- Import se nesmí zapnout bez ověřeného mapování a výslovně potvrzeného `LEGACY_SHOP_IMPORT_FROM` nebo `LEGACY_SHOP_MIN_ORDER_ID`.
- Legacy databázový účet musí mít pouze oprávnění `SELECT`; aplikace do PrestaShopu nikdy nezapisuje.
- Názvy tabulek, sloupců, stavů úhrady ani procesních hodnot se nesmí odhadovat. Všechny mapy musí být ručně ověřeny a nakonfigurovány přes `.env`.
- Produkční připojení bylo 2026-11-12 ověřeno pouze read-only diagnostikou popsanou níže. Původní dry-run Fáze 1B byl odmítnut před čtením; specializovaný dry-run Fáze 1C již sestaví kandidáta přes normalizované JOINy. Mapování zůstává neověřené, import vypnutý a scheduler neaktivovaný.

### Živá read-only diagnostika 2026-11-12

Diagnostika proběhla proti produkční legacy databázi bez zápisu. `SHOW GRANTS` nepotvrdil žádné z oprávnění `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, `DROP`, `FILE` ani `ALL PRIVILEGES`; účet je z pohledu ověřených grantů read-only. V `information_schema` bylo nalezeno 206 tabulek. Následující přehled uvádí pouze tabulky a sloupce relevantní pro import; nebyly čteny ani uloženy hodnoty zákaznických polí, VIN, cen, přihlašovacích údajů ani skutečné identifikátory objednávek.

Vestavěný příkaz `legacy-shop:diagnose` navázal spojení, ale nedokončil introspekci. Použitá legacy verze MySQL nemá v `information_schema.columns` sloupec `generation_expression`, který očekává aktuální Laravel schema builder. Schéma proto bylo ověřeno ekvivalentními read-only dotazy nad `information_schema.columns`.

| Tabulka | Ověřené relevantní sloupce |
| --- | --- |
| `ps_orders` | `id_order`, `id_carrier`, `id_customer`, `id_cart`, `id_address_delivery`, `id_address_invoice`, `payment`, `total_paid`, `total_paid_real`, `valid`, `invoice_number`, `date_add`, `date_upd` |
| `ps_order_detail` | `id_order_detail`, `id_order`, `product_id`, `product_name`, `product_reference`, `product_quantity`, `product_price` |
| `ps_order_history` | `id_order_history`, `id_order`, `id_order_state`, `date_add` |
| `ps_order_state` / `ps_order_state_lang` | `id_order_state`; lokalizované `name`, `template` |
| `ps_customer` | `id_customer`, `firstname`, `lastname`, `email` a další zákaznická pole (hodnoty nebyly čteny) |
| `ps_address` | vazby na zákazníka/zemi a fakturační či dodací pole (hodnoty nebyly čteny) |
| `ps_country` | `id_country`, `iso_code` |
| `ps_product` | `id_product`, `reference`, `deposit_amount`, `shop`, `oem` |
| `ps_product_lang` | `id_product`, `id_lang`, `name` |
| `ps_cart_vehicle_data` | `id_cart`, `vin`, `make`, `model`, `year`, `fuel`, `power`, `capacity` |
| `ps_order_vin_check` | `id_order`, `id_product`, `vin`, `vin_check_status`, `source` |
| `ps_carrier` | `id_carrier`, `name` |
| `ps_repase_manual_orders` | `id_order`, `id_cart`, `product_reference`, `repair_type`, `status`, časové sloupce; osobní sloupce nebyly čteny |

### Anonymizované logické mapování

Živé schéma nemá jednu plochou objednávkovou tabulku. Níže je kandidátní logické mapování přes více tabulek; není to zatím platná konfigurace importeru a nesmí být označeno `LEGACY_SHOP_MAPPING_VERIFIED=true`.

| Cílový atribut | Kandidátní legacy zdroj | Stav |
| --- | --- | --- |
| `source_order_id` | `ps_orders.id_order` | dostupné; v reportu záměrně nepublikováno |
| `order_no` | `ps_orders.id_order` nebo `invoice_number` | nejasné, vlastník musí určit obchodní číslo objednávky |
| `created_at` | `ps_orders.date_add` | dostupné; musí se potvrdit časové pásmo |
| `payment_status` | existence libovolného `ps_order_history.id_order_state=2` (`Payment accepted`) | potvrzená jediná autorita úhrady; poslední stav, `valid` a částky jsou jen diagnostické |
| zákazník | `ps_customer` + fakturační/dodací `ps_address` + `ps_country.iso_code` | dostupné přes join a skládání jména; importer umí jen jeden přímý sloupec |
| `product_code`, `product_name` | `ps_order_detail.product_reference`, `product_name` | dostupné; je nutné rozhodnout objednávky s více položkami |
| `vin` | `ps_order_vin_check.vin`, záložně `ps_cart_vehicle_data.vin` | dostupné volitelně; priorita zdrojů není potvrzena |
| `vehicle` | složenina polí `ps_cart_vehicle_data` | dostupné volitelně; formát není potvrzen |
| `order_type` | `ps_orders.schvaleno` | potvrzeno: `0` čeká na klasifikaci bez erroru, `1` skladová repase, `2` zakázková repase; `NULL`/jiná hodnota je chyba |
| `technical_type` | `ps_order_detail.product_id → ps_product.omacka` | potvrzená normalizace je popsána ve Fázi 1E; ověřený portálový registr může hodnotu opravit/override |
| `routing` | výchozí hodnota podle technického typu | `WORM→KRAFT`, `HYDRAULIC/EPS→ARGO`; override konkrétní zakázky nemění produktové přednastavení |
| `workflow_scenario` | `schvaleno=2` → `CUSTOM_REBUILD`; `schvaleno=1` → `PENDING_STOCK_CHECK` | Děčín/Polsko později určí výhradně Pohoda snapshot |
| `customer_handover` | `ps_orders.id_carrier` + diagnostický `ps_carrier.name` | potvrzeno jen `18→GLS`, `19/20→PERSONAL_PICKUP`; ostatní vrací `DELIVERY_MAPPING_REQUIRED` |
| `repair_subtype` | schválený použitý `ps_offer_vin_token.offer_mode` přes přímé `id_order` | `schvaleno=2` standardně `custom_repair`; pouze explicitní `catalog_repair` přepne podtyp; konfliktní režimy jsou chyba |
| `price` | `ps_orders.total_paid` nebo položkové hodnoty v `ps_order_detail` | nejasná obchodní definice a DPH |
| `deposit` | `ps_product.deposit_amount` | sloupec existuje, ale musí se potvrdit, zda jde o historický snapshot objednávky |

### Vybraná objednávka a dry-run

Pro první pokus byla explicitně vybrána nejnovější objednávka s jednou položkou, která splnila všechny tři read-only podmínky: obsahuje stav `Payment accepted`, má `valid=1` a evidovanou zaplacenou částku alespoň ve výši objednávky. V dokumentaci je identifikována pouze nereverzibilním krátkým otiskem `d0b1b3ae75b2`.

- vznikla `2026-10-26 10:12:50`, stav `Payment accepted` obdržela `2026-11-10`,
- metoda platby/předání je `Osobní odběr`, dopravce `Osobní odběr v Děčíně`,
- má jednu položku, dostupný kód i název produktu a `repair_type=stock`,
- nemá uložený VIN ani vozidlo,
- nemá potvrzený technický typ, routing ani workflow scénář.

Spuštěný příkaz:

```bash
php8.4 artisan legacy-shop:import --dry-run --limit=1
```

Dry-run Fáze 1B byl podle tehdejšího bezpečnostního návrhu odmítnut hláškou `Legacy mapping has not been explicitly verified.` Počty portálových objednávek, integračních eventů a checkpointů byly před i po běhu shodně `0`; nic nebylo importováno ani zapsáno. Omezení jednotabulkového readeru a chybějící přesný výběr objednávky řeší specializovaný reader Fáze 1C níže.

### Nejasné nebo chybějící atributy a blokátory

- potvrdit obchodní význam `order_no` (`id_order` versus `invoice_number`),
- potvrdit legacy časové pásmo; mapper nyní parsuje zdrojové datum jako UTC,
- provozně doplnit registry override pouze pro produkty s neznámou/chybnou `omacka` nebo výjimkou routingu,
- dodat aktuální Pohoda snapshot a potvrdit jeho kontrakt pro rozhodnutí skladového scénáře,
- potvrdit význam `Mech_el` a `Mech_bez`; do té doby vrací `PRODUCT_CONFIGURATION_REQUIRED`,
- rozhodnout mapování historických carrier ID `13`, `15` a `16`; do té doby vrací `DELIVERY_MAPPING_REQUIRED`,
- potvrdit prioritu VIN a formát složeného vozidla,
- rozhodnout zacházení s více položkami objednávky; současný cílový model očekává jeden produkt na objednávku,
- potvrdit význam ceny (objednávka versus položka, s/bez DPH) a zálohy,
- doplnit explicitní `LEGACY_SHOP_IMPORT_FROM` nebo `LEGACY_SHOP_MIN_ORDER_ID`,
- diagnostická introspekce a bezpečné JOINy byly technicky vyřešeny ve Fázi 1C; obchodní mapy uvedené výše zůstávají neuzavřené.

Do vyřešení všech bodů zůstávají `LEGACY_SHOP_MAPPING_VERIFIED=false` a `LEGACY_SHOP_IMPORT_ENABLED=false`.

### Fáze 1C – specializovaný PrestaShop 1.4.11 reader

Aktuální implementace již nepoužívá `LEGACY_SHOP_ORDERS_TABLE` ani mapu přímých sloupců jedné tabulky. `PrestaShop14OrderReader` skládá kanonického kandidáta z `ps_orders`, zákazníka, fakturační adresy, vozidla, poslední historie, položek, produktů a bezpečných platebních příznaků. Podrobný návrh, validační kódy a testy jsou v `docs/PHASE_1C.md`.

- Objednávky pocházejí výhradně z `ps_orders`.
- `ps_poptavky` obsahuje jen poptávky, nemá potvrzenou objednávkovou vazbu a není součástí readeru; objednávka bez poptávky je platný vstup.
- `source_order_id = ps_orders.id_order`; `order_no = 4001 + id_order` pro Pohodu.
- `product_id=91173` je záloha a nevstupuje do výběru řízení.
- Po vyloučení zálohy musí existovat právě jedna položka řízení.
- Dopravci 19 a 20 mapují na `PERSONAL_PICKUP`, carrier 18 na `GLS`; ostatní ID se na GLS neodhadují.
- Úhrada je potvrzena výhradně existencí stavu 2 kdykoli v `ps_order_history`; `total_paid`, `valid`, poslední stav a platební tabulky zůstávají jen v diagnostice.
- `schvaleno=0` znamená čekání na klasifikaci ve starém `objednavky.php`; nevzniká portálová objednávka ani integrační error. `1` znamená skladovou a `2` zakázkovou repasi. `NULL` a jiné hodnoty jsou viditelná integrační chyba. U dvojky je výchozí podtyp `custom_repair`; pouze schválený použitý offer token s `offer_mode=catalog_repair` nastaví `catalog_repair`.
- Čekající zdrojová ID jsou bezpečně vedena v `integration_checkpoints.metadata.pending_source_order_ids` a při každém běhu znovu načtena, takže je vyšší checkpoint po změně `0 → 1/2` nepřeskočí.
- Živý dry-run kandidáta `b88ed8beba19` se `schvaleno=0` vrátil `pending=1`, `errors=0` a zachoval portálové počty `0|0|0`.
- Technický typ poskytuje `ps_product.omacka`, routing se určí potvrzenou výchozí mapou a ověřený produktový registr zůstává možností opravy/override. Skladová repase bez Pohoda snapshotu zůstává `PENDING_STOCK_CHECK` u AUTOKLUBY a nemá polskou větev.
- Diagnostika používá `LegacySchemaInspector` s `SHOW TABLES`, `SHOW COLUMNS` a `SELECT VERSION()`; Laravel Schema introspekce není pro legacy MySQL použita.

Živý dry-run přes `--dry-run --order-id=<id> --limit=1` sestavil kandidáta `d0b1b3ae75b2`. Po potvrzení `schvaleno=1` již rozpoznal skladovou repasi a vrátil jen zbývající anonymizované blokátory technického typu, routingu a skladového scénáře. Počty portálových objednávek, eventů a checkpointů zůstaly `0|0|0`.

### Fáze 1D – uzavřená integrační smlouva

Aktuální smlouva, anonymizovaný produkční report, produktový registr, skladový stop stav a dva živé dry-runy jsou popsány v `docs/PHASE_1D.md`. Produkční `LEGACY_SHOP_IMPORT_ENABLED=false` a `LEGACY_SHOP_MAPPING_VERIFIED=false` zůstávají beze změny.

### Fáze 1E – produkt a dokončení legacy klasifikace

Normalizace `omacka`, výchozí routing, podtyp nabídky, přesná mapa dopravců, ověření referenčního zdrojového kódu a opakované živé dry-runy jsou popsány v `docs/PHASE_1E.md`.

## Pohoda

- `POHODA_BRIDGE_ENABLED=false` je povinný výchozí stav.
- Portál nesmí navazovat příchozí spojení do firemní sítě.
- Bridge a podepisované API patří do Fáze 7.
