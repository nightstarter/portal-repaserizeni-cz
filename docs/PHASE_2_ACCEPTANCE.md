# Fáze 2E – závěrečná akceptace Fáze 2

Akceptace proběhla 12. listopadu 2026. Automatizovaná sada Fází 1 a 2 prochází; produkční migrace nebyly spuštěny a produkční data nebyla změněna. Fáze 3 nebyla zahájena.

## Výsledek

- `CUSTOM_REBUILD` prošel oběma úplnými cestami, autorizací organizací, příjmovou kontrolou, routingem, auditem, časovou osou a QR oprávněním.
- `STOCK_REBUILD` prošel čekáním na důvěryhodný snapshot, oběma výsledky rozhodnutí, přesnými počty větví/kusů, polským skladovým procesem, oběma zákaznickými předáními a odvozeným uzavřením.
- Povinné negativní bezpečnostní scénáře procházejí v doménových i HTTP feature testech.
- Regrese Fáze 1 prochází nad fixture databázemi; legacy účet byl navíc čtecím dotazem ověřen jako SELECT-only.
- `composer check` prochází: **244 testů, 723 assertions**.
- Produkce má jednu pending rozšiřující migraci. Její přesný audit a pořadí nasazení jsou v `docs/PHASE_2_DEPLOYMENT.md`.

## Akceptace workflow 2A–2D

| Oblast | Ověření | Výsledek |
|---|---|---|
| CUSTOM_REBUILD GLS a osobní odběr | `CustomRebuildWorkflowTest`, `CustomRebuildStateMachineTest` | Obě úplné cesty a všechny nepovolené přeskoky ověřeny. |
| Organizace a role | `CustomRebuildWorkflowTest`, `Phase2bCustomRebuildUiTest` | Akce AUTOKLUBY/POLSKO jsou oddělené v command guardu, policy i přímém HTTP přístupu. |
| Příjem, routing, axiální táhla | `CustomRebuildWorkflowTest` | Povinná data, routing konkrétní zakázky a odvozený nefinanční příznak 700 Kč ověřeny bez změny produktového registru. |
| Audit, timeline a QR | `Phase2bCustomRebuildUiTest` | Události/audit jsou konzistentní, polská timeline je filtrovaná a QR vyžaduje přihlášení i row-level oprávnění. |
| PENDING_STOCK_CHECK | `InitialProcessThreadFactoryTest`, `StockRebuildWorkflowTest`, `Phase2dStockRebuildUiTest` | Chybějící i zastaralý snapshot nic nematerializují a nevytvářejí polskou viditelnost ani ruční HTTP rozhodnutí. |
| Dostupné v Děčíně | `StockRebuildWorkflowTest`, `Phase2dStockRebuildUiTest` | Přesně 3 větve a 3 odlišné fyzické kusy. |
| Nedostupné v Děčíně | `StockRebuildWorkflowTest` | Přesně 2 větve a 2 odlišné fyzické kusy, bez `STOCK_REPLENISHMENT`. |
| STOCK_REQUEST / STOCK_REPLENISHMENT / CORE_RETURN | `StockRebuildStateMachineTest`, `StockRebuildWorkflowTest`, `Phase2dStockRebuildUiTest` | Paralelní stavové automaty, oddělené projekce, povinná čekající vratka a row-level přístup ověřeny. |
| Polská dostupnost, ETA, odeslání a příjem | `StockRebuildWorkflowTest`, `Phase2dStockRebuildUiTest` | Povinná budoucí ETA, její změna s novou událostí a celý transfer jsou ověřeny. |
| GLS, osobní odběr a uzavření | `StockRebuildWorkflowTest`, `Phase2dStockRebuildUiTest` | Zákaznická větev končí pouze v `SENT_CUSTOMER` nebo `PICKED_UP`; aktivní povinná větev brání `closed_at`. |

## Bezpečnostní akceptace

| Požadavek | Důkaz |
|---|---|
| Nelze přeskočit nepovolený stav | Exhaustivní `CustomRebuildStateMachineTest` a `StockRebuildStateMachineTest`; přímé zakázané UI akce ověřují také feature testy. |
| Polsko nemůže akci AUTOKLUBY a běžný AUTOKLUBY uživatel nemůže polskou akci | Role a organizace ověřují workflow testy obou typů repase i přímé HTTP scénáře. |
| Cizí zakázka není přes dashboard, URL ani QR | `Phase2bCustomRebuildUiTest` a `Phase2dStockRebuildUiTest` ověřují seznam, detail i token; neplatný a cizí token vrací shodně 404. |
| Polsko nevidí zákaznické PII | Obě UI sady ověřují absenci adresy, telefonu, e-mailu, interních poznámek i citlivých timeline metadat. |
| ADMIN_OVERRIDE vyžaduje důvod a plný audit | Workflow i UI testy ověřují odmítnutí bez důvodu, roli `AK_ADMIN` a audit úspěšné změny. |
| Duplicate idempotency key nevytvoří druhou událost | Workflow testy CUSTOM i STOCK a UI test opakovaného POSTu porovnávají počty událostí/auditů. |
| Dva souběžné požadavky nevytvoří dvě změny | Testy optimistic concurrency a souběžné materializace ověřují jednu změnu a databázové unikátní guardy. |
| Chyba eventu nebo auditu vrátí transakci | `CustomRebuildWorkflowTest` a `StockRebuildWorkflowTest` injektují chybu uprostřed zápisu a ověřují stav i počty všech záznamů. |
| HTTP uživatel nemůže rozhodnout Pohodu | `Phase2dStockRebuildUiTest` a `PhaseTwoAcceptanceTest` ověřují absenci formuláře i browser route; rozhodnutí přijímá pouze důvěryhodné rozhraní. |
| CSRF a serverová validace | Formuláře obsahují token; `PhaseTwoAcceptanceTest` mimo testovací middleware bypass očekává 419. Povinná data, ETA, verze a role ověřují command služby i feature testy. |

## Regrese Fáze 1

`LegacyShopImportTest` a `LegacyShopDiagnosticTest` ověřují `schvaleno=0` jako pending, mapování `schvaleno` 1/2, `omacka`, platbu podle historického stavu 2, idempotentní import, neznámý produkt i dopravce a dry-run bez zápisu. Testy používají SQLite fixture databáze, nikoli produkční zápis.

Přímá čtecí kontrola `SHOW GRANTS FOR CURRENT_USER()` na legacy připojení potvrdila SELECT oprávnění a žádné `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `CREATE`, `DROP`, `FILE`, `SUPER`, `TRIGGER`, `EXECUTE` ani `GRANT OPTION`. `LEGACY_SHOP_IMPORT_ENABLED=false` a `LEGACY_SHOP_MAPPING_VERIFIED=true` zůstávají v nasazeném prostředí zachované.

## Repozitář a provoz

- Před prací na 2E byl pracovní strom čistý. Všechny commity do Fáze 2D byly shodné s `origin/main`; nový commit Fáze 2E zůstává podle zadání lokální a nebude pushnut.
- `.env` ani soubory `storage/app/private/legacy-shop-*-report.json` nejsou verzované. Heuristický scan verzovaných souborů nenašel privátní klíče ani běžné token/password vzory; nástroj `gitleaks` nebyl v prostředí dostupný.
- Nasazené prostředí má `APP_DEBUG=false`, `LEGACY_SHOP_IMPORT_ENABLED=false`, `LEGACY_SHOP_MAPPING_VERIFIED=true` a `POHODA_BRIDGE_ENABLED=false`. `APP_ENV=local` je známá deployment odchylka, která musí být před produkčním otevřením změněna na `production`.
- Routy `GET /login` a `GET /q/{randomToken}` existují. HTTPS kontrola vrátila `/login` 200 a anonymní QR 302 na login.
- HTTPS pokusy o `/.env`, `/docs/PHASE_2D.md`, `/vendor/autoload.php` a `/storage/logs/laravel.log` vrátily 404.
- Crontab aktuálního aplikačního uživatele je prázdný, aplikace nedefinuje schedule a neběžel proces `artisan schedule`, queue ani import. To není tvrzení o cizích systémových cronech mimo oprávnění procesu.
- Produkční migrace nebyla spuštěna. Stav a rizika jediné pending migrace dokumentuje deployment audit.

## Známá omezení

- Browser smoke test nebyl proveden: prostředí neposkytovalo použitelný browser ani Playwright/Node runtime. Automatizované HTTP, HTML, route a responsive-template testy prošly, ale nenahrazují vizuální kontrolu.
- Pohoda bridge není živě napojen; mimo testy resolver bezpečně vrací neznámý stav.
- `CORE_RETURN` je ve Fázi 2 záměrně pouze povinná čekající větev. Finance, vratkový detail, zásilky, tracking, dokumenty, diskuse a notifikace nejsou součástí akceptace.
- Produkce čeká na migraci `2026_11_12_000005_add_phase_2c_domain_guards`; bez ní se kód Fáze 2C/2D nesmí otevřít pro zápisy.
- Scan tajemství byl heuristický, protože specializovaný scanner nebyl instalován.

## Pending produkční migrace

1. `2026_11_12_000005_add_phase_2c_domain_guards` – přidává instanční sloupce a unikátní ochrany procesních větví a fyzických kusů. Podrobný SQL, lock analýza, kompatibilita a rollback jsou v `docs/PHASE_2_DEPLOYMENT.md`.

## Přesný smoke-test plán po nasazení

1. Před servisním oknem ověřit zálohu, verzi DB, migrace, duplicity, dlouhé transakce a všechny bezpečnostní přepínače.
2. Nasadit commit, spustit jedinou auditovanou migraci a znovu ověřit její sloupce/indexy i `migrate:status`.
3. Ověřit HTTPS 200 na `/login`, redirect anonymního `/q/{test-token}` na login a 404 pro `.env`, `docs`, `vendor` a neveřejné `storage`.
4. Přihlásit samostatný testovací účet AUTOKLUBY a POLSKO bez reálných zákaznických dat; ověřit českou a polskou lokalizaci a row-level dashboardy.
5. Nad bezpečnými staging/testovacími fixtures ověřit jednu kompletní zakázkovou GLS cestu a jednu cestu osobního odběru, včetně příjmu, routingu, timeline a QR návratu po loginu.
6. Ověřit `PENDING_STOCK_CHECK`: text o chybějícím/zastaralém snapshotu, žádné ruční rozhodovací tlačítko a nulovou polskou viditelnost.
7. Nad dvěma bezpečnými staging fixtures materializovat dostupnou a nedostupnou skladovou objednávku; ověřit přesně 3/3 a 2/2 větví/kusů a nezaměnitelné QR role.
8. Jako POLSKO zadat a změnit budoucí ETA, potvrdit dostupnost a odeslat; jako AUTOKLUBY potvrdit příjem a dokončit GLS i osobní předání.
9. Ověřit, že cizí detail/QR vrací 404, polská projekce neobsahuje PII a dokončené zákaznické plnění nezavře objednávku s aktivním doplněním nebo `CORE_RETURN`.
10. Ověřit události/audit, aplikační a DB logy a absenci duplicit. Import, scheduler a Pohoda bridge ponechat vypnuté; bez zvláštního schválení nevytvářet produkční demo objednávky.

Browser smoke test tedy **dosud nebyl proveden**. Import a scheduler **zůstávají vypnuté**.
