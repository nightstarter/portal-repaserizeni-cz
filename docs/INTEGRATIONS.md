# Integrace

Nejsou aktivní žádné produkční integrace.

## Starý e-shop

- `LEGACY_SHOP_IMPORT_ENABLED=false` je povinný výchozí stav.
- Fáze 1B obsahuje bezpečně vypnuté připojení, read-only diagnostiku a importní službu; podrobnosti jsou v `docs/PHASE_1B.md`.
- Import se nesmí zapnout bez ověřeného mapování a výslovně potvrzeného `LEGACY_SHOP_IMPORT_FROM` nebo `LEGACY_SHOP_MIN_ORDER_ID`.
- Legacy databázový účet musí mít pouze oprávnění `SELECT`; aplikace do PrestaShopu nikdy nezapisuje.
- Názvy tabulek, sloupců, stavů úhrady ani procesních hodnot se nesmí odhadovat. Všechny mapy musí být ručně ověřeny a nakonfigurovány přes `.env`.
- Produkční připojení bylo 2026-11-12 ověřeno pouze read-only diagnostikou popsanou níže. Mapování zůstává neověřené, dry-run byl bezpečně odmítnut před čtením objednávky, import zůstává vypnutý a scheduler není aktivován.

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
| `payment_status` | historie `ps_order_history.id_order_state=2` (`Payment accepted`) společně s `ps_orders.valid=1` a kontrolou zaplacené částky | více polí; vlastník musí potvrdit přesnou autoritu a pravidlo |
| zákazník | `ps_customer` + fakturační/dodací `ps_address` + `ps_country.iso_code` | dostupné přes join a skládání jména; importer umí jen jeden přímý sloupec |
| `product_code`, `product_name` | `ps_order_detail.product_reference`, `product_name` | dostupné; je nutné rozhodnout objednávky s více položkami |
| `vin` | `ps_order_vin_check.vin`, záložně `ps_cart_vehicle_data.vin` | dostupné volitelně; priorita zdrojů není potvrzena |
| `vehicle` | složenina polí `ps_cart_vehicle_data` | dostupné volitelně; formát není potvrzen |
| `order_type` | `ps_repase_manual_orders.repair_type` | nalezeny bezpečné hodnoty `custom`, `stock`; cílové mapování vyžaduje potvrzení |
| `technical_type` | nenalezen jednoznačný zdroj | chybí |
| `routing` | nenalezen jednoznačný zdroj | chybí; `ps_product.shop` označuje web, nikoli potvrzený routing |
| `workflow_scenario` | nenalezen přímý zdroj | chybí; nesmí se odvodit jen z `repair_type` |
| `customer_handover` | `ps_carrier.name` | nalezeny hodnoty `DPD`, `GLS`, `Česká pošta`, `Osobní odběr v Děčíně`; cílové hodnoty musí potvrdit vlastník |
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

Dry-run byl podle bezpečnostního návrhu odmítnut hláškou `Legacy mapping has not been explicitly verified.` Počty portálových objednávek, integračních eventů a checkpointů byly před i po běhu shodně `0`; nic nebylo importováno ani zapsáno. Příkaz navíc v současné podobě neumí omezit běh na přesně jedno ID a reader neumí spojit normalizované tabulky, proto nelze vybranou objednávku korektně předat mapperu bez předchozí změny integrační vrstvy nebo schváleného read-only zdroje/view.

### Nejasné nebo chybějící atributy a blokátory

- potvrdit obchodní význam `order_no` (`id_order` versus `invoice_number`),
- potvrdit autoritativní pravidlo „uhrazeno“; stav 2 se v historii může objevit vícekrát a u vybraného kandidáta následoval po dřívějším stornu,
- potvrdit legacy časové pásmo; mapper nyní parsuje zdrojové datum jako UTC,
- určit zdroj a mapu `technical_type` → `WORM` / `HYDRAULIC` / `EPS`,
- určit zdroj a mapu `routing` → `KRAFT` / `ARGO`,
- určit zdroj a mapu `workflow_scenario` → `CUSTOM_REBUILD` / `STOCK_DECIN` / `STOCK_POLAND` / `BUYOUT`,
- potvrdit `repair_type` `custom` / `stock` vůči cílovému `order_type`, včetně případného `BUYOUT`,
- potvrdit úplnou mapu dopravců/předání a cílové řetězce `customer_handover`,
- potvrdit prioritu VIN a formát složeného vozidla,
- rozhodnout zacházení s více položkami objednávky; současný cílový model očekává jeden produkt na objednávku,
- potvrdit význam ceny (objednávka versus položka, s/bez DPH) a zálohy,
- doplnit explicitní `LEGACY_SHOP_IMPORT_FROM` nebo `LEGACY_SHOP_MIN_ORDER_ID`,
- upravit diagnostickou introspekci pro legacy MySQL bez `generation_expression`,
- rozšířit reader o bezpečné joiny/aliasy nebo dodat schválený read-only plochý zdroj; současná konfigurace jedné tabulky a jednoho sloupce na atribut živému schématu neodpovídá.

Do vyřešení všech bodů zůstávají `LEGACY_SHOP_MAPPING_VERIFIED=false` a `LEGACY_SHOP_IMPORT_ENABLED=false`.

## Pohoda

- `POHODA_BRIDGE_ENABLED=false` je povinný výchozí stav.
- Portál nesmí navazovat příchozí spojení do firemní sítě.
- Bridge a podepisované API patří do Fáze 7.
