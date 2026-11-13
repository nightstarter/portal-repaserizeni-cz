# Fáze 3 – deployment audit a plán

Datum read-only auditu: 2026-11-13. Produkční DB běží na `10.11.14-MariaDB-0ubuntu0.24.04.1-log` a aplikace v `production` s `APP_DEBUG=false`.

## Aktuální stav migrací

`php8.4 artisan migrate:status` byl spuštěn read-only. Všechny migrační soubory jsou v batchi 1 jako `Ran`:

```text
2026_11_11_000001_create_organizations_table
2026_11_11_000002_create_users_table
2026_11_11_000003_create_audit_log_table
2026_11_11_000004_create_runtime_tables
2026_11_12_000001_create_phase_1a_domain_tables
2026_11_12_000002_link_audit_log_to_orders
2026_11_12_000003_create_legacy_product_configurations_table
2026_11_12_000004_add_phase_2a_workflow_guards
2026_11_12_000005_add_phase_2c_domain_guards
2026_11_12_000006_create_phase_3a_shipments
```

**Přesný seznam pending migrací: žádné (`[]`).** Proto neexistuje další pending migrace, pro kterou by bylo nutné hodnotit účel, schéma, kompatibilitu, lock, rollback nebo pořadí. Produkční `migrate` se během 3C nespouštěl.

## Audit migrace 000006

Migrace byla aplikována během incidentu a nesmí být editována. Aktuální SHA-256:

```text
5ffb3b0dee596ad369764c3f858531f8e56f517032aeeb51e3eec7d2731abd34
```

Hodnota přesně odpovídá `docs/INCIDENT_2026-11-12.md`. Případná budoucí oprava schématu musí být nová následná migrace.

Účel 000006 je vytvořit `shipments`, historické `shipment_items`, neměnné `shipment_events` a nullable FK `items.active_shipment_id`. Použité bigint FK, varchar, text, JSON, timestamp, indexy a unikátní klíče jsou kompatibilní s MariaDB 10.11.14; produkční metadata potvrzují jejich úspěšnou aplikaci.

Původní lock riziko bylo nízké pro vytvoření nových tabulek, ale `ALTER items` při přidání sloupce/FK mohl čekat na metadata lock a podle velikosti tabulky vyžadovat rebuild. Proto by se stejný typ změny plánoval do servisního okna. Migrace je však už aplikována a žádný takový DDL nyní není pending.

`down()` odstraňuje FK/sloupec a všechny tři tabulky, tedy maže historii. Nesmí se použít jako běžný aplikační rollback.

## Produkční schéma a data

Read-only stav během 3C:

| Objekt | Počet |
|---|---:|
| `shipments` | 0 |
| `shipment_items` | 0 |
| `shipment_events` | 0 |

Počty byly stejné před i po plném testovacím běhu.

Klíčové unikátní indexy:

- `shipments.public_number`;
- `shipments(carrier, tracking)`;
- `shipment_items(shipment_id, item_id)`;
- `shipment_events.idempotency_key`.

Další indexy pokrývají direction/status, sender/recipient/creator, shipment/action/performed_at, item, process thread a `items.active_shipment_id`. `items.active_shipment_id` záměrně není unique: jedna zásilka má mnoho itemů, ale každý item má jediný scalar FK, a proto nejvýše jednu aktivní zásilku. Row lock a podmíněné update v `ShipmentService` chrání souběžnou rezervaci. Historii chrání samostatné řádky `shipment_items`.

FK existují z `shipments` na obě organizace a creator usera, ze `shipment_items` na shipment/item/thread, ze `shipment_events` na shipment/actor usera/organizaci a z `items.active_shipment_id` na shipment. Všechny jsou na existující primární klíče a migrační definice používá `RESTRICT ON DELETE`.

## Deployment pořadí

Protože nejsou pending migrace, plánovaný deployment Fáze 3 je pouze kódový:

1. Oznámit krátké servisní okno a ověřit dostupnou obnovitelnou DB zálohu.
2. Ponechat `LEGACY_SHOP_IMPORT_ENABLED=false`, `POHODA_BRIDGE_ENABLED=false`; neaktivovat scheduler.
3. Ověřit `APP_ENV=production`, `APP_DEBUG=false` a znovu porovnat checksum 000006.
4. Nasadit přesně auditované commity bez rebase; spustit `composer install` pro produkci a cache příkazy podle standardního deploymentu.
5. Znovu spustit pouze `php8.4 artisan migrate:status`. Pokud by se objevil jakýkoli nový pending soubor, deployment zastavit a provést nový audit; v aktuálním stavu se `migrate` nespouští.
6. Provést níže uvedený browser smoke test s explicitně schválenými testovacími zakázkami; nevytvářet anonymní produkční demo data.
7. Ověřit logy, eventy, audity, aktivní rezervace a absenci duplicit. Import, Pohoda bridge a scheduler ponechat vypnuté.

## Ruční browser smoke-test checklist

Provést na desktopu a znovu v mobilním viewportu **390 × 844 px**:

1. Přihlásit se jako `AK_OPERATOR`, otevřít Zásilky a ověřit prázdný/aktuální seznam bez cizích údajů.
2. Vytvořit `AK_TO_PL + GLS`; ověřit jedno tracking pole. Přepnout na `SVOZ_AUTOKLUBY`; tracking musí zmizet a nesmí být odeslán.
3. Otevřít připravenou zásilku. Ověřit číslo, směr, carrier, `PREPARED`, sender/recipient a prázdnou historii/položky podle skutečnosti.
4. Naskenovat platný QR hardwarovou čtečkou jako token a druhý jako celou URL `/q/{token}`. Řádky se musí objevit jednou se správnou zakázkou, rolí, větví a lokalitou.
5. Zkusit neplatný, směrově cizí a duplicitní QR. Zobrazí se bezpečná chyba a počet položek ani verze se částečně nezmění.
6. Pokud browser podporuje `BarcodeDetector`, povolit zadní kameru, naskenovat QR a ověřit automatický submit. Při zamítnutí kamery ověřit čitelnou fallback zprávu a funkční ruční vstup.
7. Odebrat item v `PREPARED`, znovu jej přidat a ověřit historii. Po odeslání už ovládání přidat/odebrat nesmí být dostupné.
8. U GLS/DPD nechat tracking prázdný a ověřit serverovou chybu; poté zadat unikátní tracking a odeslat. Dvojklik/opakovaný submit nesmí vytvořit druhou akci.
9. Jako sender zkusit přímý příjmový POST a jako recipient přímý odesílací POST; oba musí být odmítnuty bez změny DB.
10. Přihlásit se jako příjemce. Detail musí ukazovat `SENT`, carrier, direction, tracking pouze u GLS/DPD, všechny položky a historii. Potvrdit doručení a ověřit `DELIVERED`.
11. Na jiné zásilce nahlásit `TRANSPORT_ERROR`: prázdný popis odmítnout, neprázdný přijmout; rezervace zůstane. Poté potvrdit doručení.
12. Přepnout CZ/PL a ověřit navigaci, stavy, akce, validace a historii. Jako `READ_ONLY` ověřit detail bez mutačních tlačítek a přímý POST `403`.
13. V mobilním viewportu ověřit, že tabulky přecházejí na karty, horizontálně nepřetékají, hlavní tlačítka jsou dosažitelná a kamera/formulář nejsou překryté navigací.

Browser smoke test je k datu 3C **neprovedený**, protože relace neměla browser/computer-use nástroj.

## Rollback plán

- Před nasazením mít ověřenou DB zálohu a zaznamenat commit před deploymentem.
- Při chybě UI/aplikace zastavit zápisy a vrátit pouze kód na předchozí commit. Aditivní shipment tabulky a nullable sloupec ponechat; starší kód je nemusí používat.
- Automaticky nespouštět `migrate:rollback`, `migrate:reset`, `migrate:refresh`, `migrate:fresh` ani `db:wipe`.
- Pokud již existují shipmenty, před rollbackem ověřit aktivní `items.active_shipment_id`, rozpracované `PREPARED/SENT/TRANSPORT_ERROR`, eventy a audity. Nedokončené zásilky nejprve provozně uzavřít nebo zmrazit.
- Destruktivní `down()` 000006 připadá v úvahu jen jako zvlášť schválená obnova celého releasu po záloze a potvrzení, že historii lze odstranit. Preferovaná oprava schématu je vždy nová dopředná migrace.

3C produkční databázi nezměnila a tento plán sám neopravňuje žádnou produkční migraci.

