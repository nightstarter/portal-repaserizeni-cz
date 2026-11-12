# Fáze 1E – mapování produktu a dokončení legacy klasifikace

## Bezpečnost a zdroje

Diagnostika produkční databáze použila pouze `SHOW` a `SELECT`. Referenční `objednavky.php`, `update.php`, `offer_approval_service.php` a přímo použitá pomocná logika byly pouze přečteny; nebyly spuštěny, upraveny, kopírovány ani přidány do Gitu. Z referenčního stromu nebyly převzaty žádné přístupové údaje, klíče, adresy ani databázová připojení.

Starý intranet slouží pouze jako důkaz současného chování. Jeho obrazovkové stavy a technické zkratky nejsou automaticky cílovým doménovým modelem portálu.

## Technický typ a routing

Autoritou je `ps_product.omacka`, načtená přes položku `ps_order_detail.product_id`. Normalizátor hodnotu trimuje a porovnává bez ohledu na velikost písmen:

| Raw hodnoty po normalizaci | `technical_type` | výchozí routing |
| --- | --- | --- |
| `EPS`, `EPS_H`, `EPS_EL` | `EPS` | `ARGO` |
| `HYD`, `HYD_H`, `HYD_V`, `HYD_HV` | `HYDRAULIC` | `ARGO` |
| `snek` | `WORM` | `KRAFT` |

Původní raw hodnota včetně whitespace se ukládá jako `legacy_product_omacka_raw` do zdrojového snapshotu. Typ se nikdy neodvozuje z názvu ani kódu produktu.

`NULL`, prázdná a neznámá hodnota vrací `PRODUCT_CONFIGURATION_REQUIRED`. `Mech_el` a `Mech_bez` jsou výslovně neznámé do potvrzení významu.

Ověřený záznam `legacy_product_configurations` má přednost jako oprava/override. Přenáší technický typ a výchozí routing do nové objednávky, ale pozdější změna routingu konkrétní objednávky nikdy nezapisuje zpět do produktového registru.

### Anonymizovaná distribuce `omacka`

| Hodnota | Počet produktů |
| --- | ---: |
| `HYD` | 1309 |
| `EPS` | 501 |
| `Mech_el` | 279 |
| `HYD_H` | 246 |
| prázdná | 162 |
| `EPS_H` | 65 |
| `snek` | 55 |
| `Mech_bez` | 25 |
| `HYD_V` | 4 |
| `HYD_HV` | 4 |
| `EPS_EL` | 2 |
| `NULL` | 1 |

## Zakázkový podtyp

Referenční kód skutečně potvrzuje:

- `stock_product` očekává `schvaleno=1`;
- `custom_repair` i `catalog_repair` očekávají `schvaleno=2`;
- nabídka objednávky se hledá přímo v `ps_offer_vin_token` podle `id_order`, pouze pokud je použitá a VIN-schválená;
- `catalog_repair` je označen jako zakázková repase na vlastním řízení;
- `update.php` blokuje schválení do větve odporující typu nabídky.

Cílový reader vyhodnotí všechny kvalifikované režimy. `schvaleno=2` bez nabídky nebo s režimem `standard`/`custom_repair` používá `custom_repair`; jediný explicitní `catalog_repair` používá `catalog_repair`. Pokud kvalifikované záznamy obsahují více různých režimů, vrátí `REPAIR_SUBTYPE_AMBIGUOUS` místo legacy volby posledního záznamu. Režim `stock_product` u `schvaleno=2` vrátí `REPAIR_BRANCH_OFFER_MISMATCH` a jiný neznámý režim `REPAIR_SUBTYPE_UNCONFIRMED`.

Živá read-only kontrola našla u objednávky 654 právě jeden kvalifikovaný záznam s `catalog_repair`. V aktuálních `schvaleno=2` datech nebyla nalezena objednávka s konfliktními kvalifikovanými režimy.

## Způsob předání

Automatická mapa je uzavřená:

- carrier 18 → `GLS`,
- carrier 19 a 20 → `PERSONAL_PICKUP`,
- cokoli jiného → `DELIVERY_MAPPING_REQUIRED`.

Referenční kód potvrzuje, že osobní odběr tvoří právě 19/20. Carrier 18 a jeho název GLS byly potvrzeny živým číselníkem a explicitním rozhodnutím provozovatele.

### Anonymizovaný diagnostický přehled carrierů

| ID | Legacy název | Počet objednávek | Automatické mapování |
| ---: | --- | ---: | --- |
| 13 | Česká pošta | 28 | žádné |
| 15 | Osobní odběr | 4 | žádné |
| 16 | DPD | 111 | žádné |
| 18 | GLS | 444 | `GLS` |
| 19 | Osobní odběr v Děčíně | 28 | `PERSONAL_PICKUP` |
| 20 | Osobní odběr v Děčíně | 40 | `PERSONAL_PICKUP` |

## Živé read-only dry-runy

Před i po obou bězích byly počty `orders`, `integration_events` a `integration_checkpoints` nulové. Obě produkční bezpečnostní pojistky zůstaly `false`.

### Objednávka 648

- candidate hash: `94ce7f0488df3a42a56f1745402776fe8645b97ccecab1ce68ababba7165699d`
- repair type/subtype: `STOCK_REBUILD` / bez zakázkového podtypu
- raw `omacka`: `HYD`
- technical type/routing: `HYDRAULIC` / `ARGO`
- handover: `GLS`
- payment eligibility: `ELIGIBLE` díky historickému stavu 2
- stock decision: `PENDING_STOCK_CHECK`
- validační chyby: žádné; celková import eligibility zůstává `false` pouze kvůli `MAPPING_NOT_VERIFIED`

### Objednávka 654

- candidate hash: `e164ae4ee7a110fc62852489b836bc7eca0034979a69c533aea5c79ef7526ad9`
- repair type/subtype: `CUSTOM_REBUILD` / `catalog_repair`
- raw `omacka`: `HYD`
- technical type/routing: `HYDRAULIC` / `ARGO`
- handover: `GLS`
- payment eligibility: `ELIGIBLE` díky historickému stavu 2
- stock decision: `NOT_APPLICABLE`
- validační chyby: žádné; celková import eligibility zůstává `false` pouze kvůli `MAPPING_NOT_VERIFIED`

Výstupy neobsahují PII, VIN, adresy, částky ani celé objednávky.

## Testy a stop bod

Parametrizované testy pokrývají všechny potvrzené hodnoty `omacka`, rozdílnou velikost písmen, whitespace, `NULL`, prázdnou hodnotu, `Mech_el`, `Mech_bez` a jinou neznámou hodnotu. Integrační testy pokrývají raw snapshot, registry override, všechny tři potvrzené carriery, neznámého dopravce, standardní/custom katalogový podtyp a konfliktní nabídky.

`LEGACY_SHOP_IMPORT_ENABLED=false` a `LEGACY_SHOP_MAPPING_VERIFIED=false` zůstávají beze změny. Nebyl spuštěn ostrý import, scheduler, produkční migrace ani Fáze 2.
