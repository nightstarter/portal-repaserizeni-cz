# Fáze 4C — závěrečná akceptace Fáze 4

Datum auditu: 2026-11-18. Výsledek: **automatizovaná akceptace je zelená; produkční aktivace je podmíněna deployment branami v `PHASE_4_DEPLOYMENT.md`**. Nebyl proveden push, produkční migrace, import, scheduler ani zápis do produkční DB. Níže je transparentně popsaný a opravený izolovaný testovací artefakt ve private storage.

## Historie a pracovní stav

Na začátku auditu byl stav:

```text
51b052d (origin/main) feat: implement phase 4a core returns and supplier finance
7a5fa93 (HEAD)        feat: add phase 4B private documents and return finance UI
```

`git log --oneline origin/main..HEAD` obsahoval pouze 4B a pracovní strom byl čistý. Audit našel chybu účinnosti reverzovaných podmínek faktury a opravil ji samostatně:

```text
9aad79f fix: ignore reversed supplier deposit terms
ff38c20 fix: isolate diagnostic report storage in tests
```

První oprava zachovává append-only ledger, ale doménová rozhodnutí nyní pracují pouze s účinnými entries. Regresní test mění fakturu v obou směrech „se zálohou ↔ bez zálohy“. Druhá oprava převádí starší diagnostický report test na `Storage::fake` a dokládá absenci zápisu do skutečného private disku.

`.env` a `.env.testing` jsou ignorované, nejsou tracked; `.env.testing` používá výhradně SQLite `:memory:`. `storage/app/private/.gitignore` ignoruje všechny uploady kromě samotného `.gitignore`. Provozní `legacy-reference` leží mimo Git root v `../legacy-reference` a žádný jeho soubor není tracked. Projektové specifikace MASTER/IMPLEMENTACE jsou záměrně verzované dokumenty, nikoli provozní reference.

## Automatizovaná akceptace

`php8.4 composer.phar check` prošel: **302 testů, 1191 assertions**. Součástí byly Composer validace, Pint, syntax audit, statická analýza a kompletní test runner po fail-fast potvrzení SQLite `:memory:`.

Nový `Phase4AcceptanceTest` provádí pro plné, krácené i bez-zálohové vypořádání skutečnou cestu:

```text
WAITING_CORE → CORE_RECEIVED_AK → AK_TO_PL/SENT_TO_PL
→ CORE_INSPECTION → rozhodnutí → CREDIT_ISSUED → CREDIT_PAID
```

Bez-zálohová větev končí v `CORE_SETTLED_NO_DEPOSIT` bez dobropisu. Test navíc ověřuje lokaci kusu, uvolnění shipment rezervace, neměnnou auditovanou kontrolu, jiný kus bez změny produktu, zákaz polského obejití příjmu, decimal redukci `1250.55 - 1000.10 = 250.45`, idempotentní platbu a otevřenou objednávku kvůli jiné aktivní větvi.

### CORE_RETURN a zásilky

- `CORE_RETURN` je samostatný `process_thread`, nikoli globální stav objednávky; uzavření počítá `OrderCompletionService` ze všech povinných větví.
- AUTOKLUBY příjem vytváří nepřepisovatelný `core_return_inspections` s actor/time a audit. Model blokuje update i delete.
- `DIFFERENT_PIECE` nemění `orders.product_code`.
- Pouze existující `ShipmentService` provede `AK_TO_PL`; GLS i svoz, dávky, lokace, rezervace a úplný rollback jsou pokryté regresí 4A/3.
- Polská finanční akce před `CORE_INSPECTION` je zamítnuta; polský uživatel nesmí provést akci AUTOKLUBY.
- Dokončená vratka neuzavře order, pokud zůstává aktivní jiná povinná větev.

### Oddělení financí

- `CUSTOMER_DEPOSIT_SNAPSHOT` zůstává pouze v legacy business snapshotu; dodavatelský ledger používá jiné stabilní typy a odmítá jej jako source.
- End-to-end test obsahuje zákaznický snapshot `999.99 CZK`, ale dodavatelský ledger nemá žádný CZK řádek. Polské detailové testy dokazují absenci zákaznických PII.
- Plné vrácení nepřijímá klientskou částku. Krácení vyžaduje nižší nezápornou částku, stejnou měnu a důvod.
- Výpočty používají validované decimal stringy a integer minor units. Finance Fáze 4 nepoužívají `float` ani `double`.
- Bez-zálohová cesta vyžaduje účinný `SUPPLIER_DEPOSIT_NOT_CHARGED`; absence/NULL nestačí.
- `FinanceEntry` blokuje update/delete. Opravy přidávají `CORRECTION`/`REVERSAL`; reverzované entries se nezapojují do nového rozhodnutí.
- Row lock, `lock_version`, idempotency key a request hash brání dvojím verdiktům. Replay platby vytvoří právě jeden `SUPPLIER_CREDIT_PAID`.

### Dokumenty, faktury a dobropisy

- `local` disk míří na `storage/app/private`, `serve=false`; Apache DocumentRoot je pouze `web/public`.
- Fyzická cesta i route token používají náhodné UUID, nikdy původní název ani sekvenční ID.
- Allowlist je PDF/JPEG/PNG. Testy pokrývají skutečný i deklarovaný MIME, spoofing, PHP/HTML/SVG, dvojitou příponu, velikost, bezpečně normalizovaný traversal název, SHA-256 a absenci souboru v `public`. JavaScript a ostatní typy odmítá uzavřený allowlist.
- Download vyžaduje přihlášení a `DocumentPolicy`, vrací attachment, `nosniff` a `private, no-store`. Cizí i neexistující token vrací 404; invalidovaný dokument není běžně stažitelný.
- Storage selhání nepřidá DB data; DB rollback smaže nový soubor. Testy používají `Storage::fake` a produkční disk neotevírají k zápisu.
- Unikátní nullable `active_key` zajišťuje jednu aktivní fakturu větve a jeden aktivní dobropis vypořádání.
- Faktura atomicky vytváří dokument, event, audit, nákupní cenu a právě jednu explicitní variantu zálohy. Oprava invaliduje originál a přidá reverzy.
- Dobropis je povolen jen po rozhodnutí, přesně ve schválené částce a měně. Varianta bez zálohy jej odmítá. Platba vyžaduje aktivní dobropis a částku odvozuje server.

### Oprávnění, UI a KPI

- AUTOKLUBY vidí relevantní vratkové větve; Polsko pouze thready viditelné přes aktuální/historické přiřazení.
- Polská projekce nenačítá customer relation a test odmítá jméno, e-mail i telefon zákazníka.
- Guardy/policies chrání každý POST a download; skryté tlačítko není bezpečnostní hranice.
- Běžný AK operator nemění dodavatelské finance. `AK_ADMIN` override vyžaduje důvod a audit.
- KPI jsou query projekce threadů, dokumentů a ledgeru; nejsou ručně uložené.
- CZ/PL překlady a CSRF/idempotency/version fields jsou testované. Layout má plnou šířku inputs, responzivní grid a breakpoint 700 px.

## Regrese a stav produkce po auditu

Plný check zahrnul importní mapování Fáze 1, `CUSTOM_REBUILD`, `STOCK_REBUILD`, role/QR, zásilky a hromadný rollback, CORE_RETURN 4A, dokumenty 4B a `DatabaseCommandGuard`.

Před a po testech byl read-only spočítán přesný počet řádků všech produkčních tabulek. Oba snapshoty měly 22 tabulek, 128 řádků a stejný SHA-256 agregátu počtů:

```text
7a981b425c36eb35331ecffa8d562b5bf913b12f386bf38aa41cc7fa1b17749a
```

Produkční DB nebyla změněna. První check odhalil, že starší `LegacyShopDiagnosticTest` zapisoval testovací `legacy-shop-test-report.json` do skutečného `storage/app/private`. Artefakt byl přesně identifikován a odstraněn; test byl v samostatném commitu `ff38c20` převeden na `Storage::fake` a opakovaný celý check již do private storage nezapisuje. Historický `legacy-shop-phase1c-report.json` zůstal beze změny, SHA-256 `f94a111321442739e01ee9b6128855d2c4a795eb76957e8fcdf74ed10cd20790`; finální private storage obsahuje mimo `.gitignore` právě tento jediný původní report a žádný dokument Fáze 4. Adresářový mtime se kvůli vytvoření/odstranění testovacího artefaktu změnil; audit proto netvrdí, že během celé relace neproběhl žádný filesystem write, ale potvrzuje obnovený obsah a odstraněnou příčinu.

## Známá omezení a deployment brány

- PHP 8.5 FPM má `upload_max_filesize=2M`, `post_max_size=8M`; aplikace dovoluje 10 MB. Před aktivací je povinná změna na nejméně `10M/12M` a ověření po reloadu.
- Antivirová/malware kontrola není implementovaná. Allowlist a `fileinfo` nejsou náhradou AV.
- Autentizovaný browser smoke test nebyl proveden: nebyla bezpečná schválená neprodukční data/účty, zadání zakazuje produkční demo a dostupná desktopová integrace neměla v relaci vystavený ovládací nástroj. Ruční checklist je deployment brána.
- Migrace 000007 a 000008 jsou pending. Produkční aktivace vyžaduje zálohu, storage/PHP setup, servisní okno a jejich samostatné schválení.
- Invalidované dokumenty se automaticky nemažou; výchozí retence je neomezená do schválení právní/účetní politiky.
