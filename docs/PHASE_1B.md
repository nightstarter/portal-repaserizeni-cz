# Fáze 1B – bezpečná integrační vrstva legacy e-shopu

## Rozsah

Implementována je bezpečně vypnutá integrační vrstva pro budoucí import uhrazených objednávek ze starého e-shopu:

- samostatné připojení `legacy_shop`, jehož veškeré přístupové údaje pocházejí pouze z `.env`,
- read-only diagnostika připojení, tabulek, sloupců, oprávnění a nakonfigurovaného mapování,
- anonymizovaný JSON report bez zákaznických údajů, VIN, cen, identifikátorů a přihlašovacích údajů,
- explicitní mapování tabulky, sloupců, stavů úhrady a doménových hodnot přes `.env`,
- import uhrazených objednávek s dry-run režimem,
- idempotence podle `(source, source_order_id)`,
- checkpoint po poslední úspěšně zpracované objednávce,
- povinná dolní hranice `LEGACY_SHOP_IMPORT_FROM` nebo `LEGACY_SHOP_MIN_ORDER_ID`; jsou-li zadány obě, platí obě,
- transakční vytvoření objednávky, zákaznického snapshotu, počátečních procesních větví, integračního eventu a checkpointu,
- viditelný anonymizovaný integrační error při neúplné nebo nemapovatelné objednávce.

Import zůstává ve výchozím stavu vypnutý pomocí `LEGACY_SHOP_IMPORT_ENABLED=false`. Dry-run neprovádí žádný zápis do portálu. Žádná část integrace nezapisuje do PrestaShopu.

## Bezpečné použití diagnostiky

Diagnostika používá výhradně připojení `legacy_shop` a provádí pouze čtení legacy databáze. Pro produkční databázi musí být vytvořen samostatný databázový účet s oprávněním pouze `SELECT`.

```bash
php artisan legacy-shop:diagnose
```

Výchozí report vznikne v `storage/app/private/legacy-shop-mapping-report.json`. Cestu lze změnit pomocí `--output`. Report obsahuje názvy tabulek a sloupců, přítomnost mapovaných polí a bezpečné surové hodnoty pouze pro stav úhrady a procesní klasifikace. Ostatní pole uvádějí jen příznak přítomnosti.

Diagnostika se může spustit s `LEGACY_SHOP_MAPPING_VERIFIED=false`; jejím účelem je dodat podklady pro ruční ověření. Samotný import vyžaduje explicitně ověřené kompletní mapování.

## Mapování bez odhadů

Významy legacy sloupců a hodnot nejsou v kódu odhadovány. Před budoucím dry-runem musí vlastník systému ručně potvrdit:

- tabulku a sloupce objednávky, úhrady, zákazníka, produktu, VIN, ceny a zálohy,
- hodnoty, které prokazatelně znamenají uhrazenou objednávku,
- mapy typu repase, technického typu, routingu, workflow scénáře a dopravy/předání,
- časové pásmo legacy databáze,
- bezpečnou počáteční hranici importu.

Teprve poté lze nastavit `LEGACY_SHOP_MAPPING_VERIFIED=true`. Neznámá nebo chybějící hodnota se nikdy nedoplní odhadem; objednávka skončí jako `FAILED` integrační event a checkpoint se neposune.

## Importní příkaz

Bezpečný první krok po schválení mapování je pouze dry-run:

```bash
php artisan legacy-shop:import --dry-run --limit=100
```

Zápisový běh navíc vyžaduje explicitní `LEGACY_SHOP_IMPORT_ENABLED=true`. V této fázi není nastaven scheduler ani cron a produkční přepínač nebyl zapnut.

Import čte jen uhrazené objednávky splňující hranici. Každá objednávka se ukládá v samostatné transakci. Při chybě se doménové zápisy i checkpoint vrátí zpět, uloží se pouze bezpečný chybový integrační event a dávka se zastaví.

## Testovací pokrytí

Testy používají izolovanou SQLite fixture databázi na připojení `legacy_shop`; nepoužívají žádná produkční data ani síťové spojení. Pokrývají:

- opakovaný import bez duplicity,
- dry-run bez zápisů,
- povinnou a kombinovanou startovní hranici,
- neúplnou objednávku,
- rollback objednávky, zákazníka a procesních větví,
- zákaz zápisového běhu při vypnutém importu,
- anonymizaci diagnostického reportu.

## Stop bod

Fáze 1B končí před živým ověřením mapování. Nebylo navázáno spojení s produkčním e-shopem, nebyly použity skutečné přístupové údaje, nebyl spuštěn produkční dry-run ani import a nebyl aktivován scheduler.
