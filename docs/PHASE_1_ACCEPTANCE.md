# Fáze 1F – závěrečná akceptace Fáze 1

Datum akceptace: 2026-11-12.

## Výsledek

Fáze 1 je dokončena. Provozovatel schválil legacy mapování na základě potvrzené platební podmínky, klasifikace `schvaleno`, `ps_product.omacka`, výchozího routingu, `offer_mode`, dopravců a živých dry-runů.

Produkční konfigurace po akceptaci:

```dotenv
LEGACY_SHOP_MAPPING_VERIFIED=true
LEGACY_SHOP_IMPORT_ENABLED=false
```

`.env.example` ponechává obě hodnoty `false`. Konfigurační cache byla vyčištěna příkazem přes PHP 8.4 a efektivní hodnoty byly znovu načteny. Dříve existující startovní hranice nebyly ve Fázi 1F nastaveny ani změněny.

## Akceptační body

| Bod | Výsledek | Důkaz |
| --- | --- | --- |
| Mapování označeno jako ověřené | splněno | efektivní `mapping_verified=true` |
| Import zůstává vypnutý | splněno | efektivní `import_enabled=false` |
| Dry-run 648 | splněno | eligible, `STOCK_REBUILD`, `PENDING_STOCK_CHECK` |
| Dry-run 654 | splněno | eligible, `CUSTOM_REBUILD`, `catalog_repair` |
| Nulové portálové zápisy | splněno | `orders`, `integration_events`, `integration_checkpoints`: před i po `0` |
| Legacy databáze beze změny | splněno | sledované počty před/po shodné; účet nemá zápisové granty |
| `schvaleno=0` není ztraceno | splněno | regresní testy změn `0→1` a `0→2` přes vyšší checkpoint |
| Neznámá `omacka` | splněno | `PRODUCT_CONFIGURATION_REQUIRED` |
| Neznámý carrier | splněno | `DELIVERY_MAPPING_REQUIRED` |
| Deterministický dry-run | splněno | živé i fixture opakování vrací shodný výsledek |
| Referenční strom není v Gitu | splněno | žádný sledovaný soubor z `legacy-reference` |
| Repozitář bez zjištěných tajemství | splněno | kontrola sledovaných souborů; `.env` není sledovaný |
| Kompletní kontrola kvality | splněno | `composer check`: 73 testů, 257 assertions |

Neznámý produkt nebo dopravce nezneplatňuje celé mapování. Konkrétní kandidát však vždy skončí viditelnou chybou a nevytvoří portálovou objednávku.

## Živé dry-runy

Každý kandidát byl spuštěn dvakrát. Anonymizované validační objekty byly při opakování shodné.

### Objednávka 648

- candidate hash: `94ce7f0488df3a42a56f1745402776fe8645b97ccecab1ce68ababba7165699d`
- import eligible: ano
- repair type/subtype: `STOCK_REBUILD` / bez zakázkového podtypu
- raw `omacka`: `HYD`
- technical type/routing: `HYDRAULIC` / `ARGO`
- handover: `GLS`
- payment eligibility: `ELIGIBLE`
- stock decision: `PENDING_STOCK_CHECK`
- validační chyby: žádné

### Objednávka 654

- candidate hash: `e164ae4ee7a110fc62852489b836bc7eca0034979a69c533aea5c79ef7526ad9`
- import eligible: ano
- repair type/subtype: `CUSTOM_REBUILD` / `catalog_repair`
- raw `omacka`: `HYD`
- technical type/routing: `HYDRAULIC` / `ARGO`
- handover: `GLS`
- payment eligibility: `ELIGIBLE`
- stock decision: `NOT_APPLICABLE`
- validační chyby: žádné

## Kontrola zápisů

Portál před a po dry-runech:

| Tabulka | Před | Po |
| --- | ---: | ---: |
| `orders` | 0 | 0 |
| `integration_events` | 0 | 0 |
| `integration_checkpoints` | 0 | 0 |

Kontrolní agregované počty legacy tabulek byly před a po dry-runech shodné:

| Tabulka | Před | Po |
| --- | ---: | ---: |
| `ps_orders` | 655 | 655 |
| `ps_order_history` | 3129 | 3129 |
| `ps_product` | 2653 | 2653 |
| `ps_offer_vin_token` | 196 | 196 |

`SHOW GRANTS` nepotvrdil žádné zápisové ani administrátorské oprávnění legacy účtu.

## Bezpečnostní stop bod

Akceptace mapování neopravňuje ostrý import. Nebyl spuštěn ani naplánován zápisový import, cron nebo scheduler, dříve existující startovní hranice nebyla změněna a nebyla zahájena Fáze 2.
