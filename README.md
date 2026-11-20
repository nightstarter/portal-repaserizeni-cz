# Portál repase řízení

Samostatný transakční portál AUTOKLUBY ↔ POLSKO. Procesní pravidla určuje `transakcni_system_repase_MASTER_v17.md`, technickou implementaci `transakcni_system_repase_IMPLEMENTACE_v18.md`.

## Stav implementace

- [x] Fáze 0 – bootstrap a bezpečný základ (viz `docs/PHASE_0.md`)
- [x] Fáze 1 – import a doménové jádro (Fáze 1A–1F dokončeny; závěrečná akceptace viz `docs/PHASE_1_ACCEPTANCE.md`)
- [x] Fáze 2 – základní workflow repase (závěrečná akceptace a audit nasazení viz `docs/PHASE_2_ACCEPTANCE.md` a `docs/PHASE_2_DEPLOYMENT.md`)
  - [x] Fáze 2A – doménový stavový automat `CUSTOM_REBUILD` (viz `docs/PHASE_2A.md` a `docs/STATE_MACHINES.md`)
  - [x] Fáze 2B – UI `CUSTOM_REBUILD`, časová osa a mobilní QR detail (viz `docs/PHASE_2B.md`)
  - [x] Fáze 2C – skladová repase a paralelní větve (viz `docs/PHASE_2C.md`)
  - [x] Fáze 2D – UI skladové repase a paralelních větví (viz `docs/PHASE_2D.md`)
  - [x] Fáze 2E – závěrečná akceptace Fáze 2 a audit pending migrací
- [x] Fáze 3 – zásilky a hromadné skenování
  - [x] Fáze 3A – doména zásilek AUTOKLUBY ↔ POLSKO (viz `docs/PHASE_3A.md`, `docs/SHIPMENTS.md` a `docs/STATE_MACHINES.md`)
  - [x] Fáze 3B – UI zásilek a QR skener (viz `docs/PHASE_3B.md`)
  - [x] Fáze 3C – akceptace a deployment audit (viz `docs/PHASE_3_ACCEPTANCE.md` a `docs/PHASE_3_DEPLOYMENT.md`)
  - [x] Fáze 3D – kompatibilní QR kamera s lokálním fallbackem a potvrzeným ručním browserovým scénářem; mobilní platformní kontrola zbývá
- [x] Fáze 4 – vratky a finance (automatizovaně přijata; produkční aktivace má deployment brány)
  - [x] Fáze 4A – `CORE_RETURN`, kontrola vratného dílu, dodavatelská záloha a neměnný finanční ledger (viz `docs/PHASE_4A.md`, `docs/CORE_RETURNS.md` a `docs/FINANCE.md`)
  - [x] Fáze 4B – soukromé dokumenty, dobropisy, platby, UI a KPI (viz `docs/PHASE_4B.md` a `docs/DOCUMENTS.md`)
  - [x] Fáze 4C – závěrečná akceptace a deployment audit (viz `docs/PHASE_4_ACCEPTANCE.md` a `docs/PHASE_4_DEPLOYMENT.md`)
  - [x] Fáze 4D – samostatné autorizované fronty Vratky a Doklady a finance, stabilní menu a sekční navigace detailu (viz `docs/PHASE_4D.md`)
- [x] Fáze 5 – diskuse a notifikace (automatizovaně přijata; produkční aktivace má deployment brány)
  - [x] Fáze 5A – doménové jádro diskuse, privátní přílohy, read-state a outbox bez odesílání e-mailů (viz `docs/PHASE_5A.md`)
  - [x] Fáze 5B – UI diskuse, globální a lokální unread badge, autorizovaná fronta a cursorové stránkování (viz `docs/PHASE_5B.md`)
  - [x] Fáze 5C – transakční e-mailový outbox, worker/retry, scheduler a AK_ADMIN přehled (viz `docs/PHASE_5C.md`)
  - [x] Fáze 5D – závěrečná automatizovaná akceptace, bezpečnostní/výkonový audit a deployment plán (viz `docs/PHASE_5_ACCEPTANCE.md` a `docs/PHASE_5_DEPLOYMENT.md`)
- [x] Fáze 6 – opakovatelné reklamace a systémový výkup (závěrečná akceptace a deployment viz [docs/PHASE_6_ACCEPTANCE.md](docs/PHASE_6_ACCEPTANCE.md) a [docs/PHASE_6_DEPLOYMENT.md](docs/PHASE_6_DEPLOYMENT.md))
  - [x] Fáze 6A – doménové jádro reklamačních cyklů `WARRANTY`
  - [x] Fáze 6B – UI a autorizovaná fronta reklamací
  - [x] Fáze 6C – doménové a API jádro systémového výkupu `BUYBACK`
  - [x] Fáze 6D – UI, fronta a administrace výkupů
  - [x] Fáze 6E – závěrečná automatizovaná akceptace a bezpečnostní audit
- [x] Fáze 7 – bezpečný Pohoda bridge (závěrečná akceptace, provoz a deployment viz [docs/PHASE_7_ACCEPTANCE.md](docs/PHASE_7_ACCEPTANCE.md), [docs/PHASE_7_OPERATIONS.md](docs/PHASE_7_OPERATIONS.md) a [docs/PHASE_7_DEPLOYMENT.md](docs/PHASE_7_DEPLOYMENT.md))
  - [x] Fáze 7A – portálové API Pohoda bridge, HMAC, snapshoty a příkazy
  - [x] Fáze 7B – samostatný HPSERVER konektor
  - [x] Fáze 7B.1 – omezení snapshotu na produkty s prefixem `RR`
  - [x] Fáze 7B.2 – přijetí platných nenamapovaných `RR` referencí
  - [x] Fáze 7C – fingerprint datasetu a potvrzení beze změny
  - [x] Fáze 7D – závěrečná akceptace, bezpečnostní a kapacitní audit
- [ ] Fáze 8 – rozpracovaná produkční připravenost
  - [x] Fáze 8A – bezpečnostní baseline, permission matrix a provozní preflight (dokončeno, viz [docs/PHASE_8A.md](docs/PHASE_8A.md))
  - [ ] Fáze 8B – zálohy, obnova a disaster recovery
  - [ ] Fáze 8C – monitoring, health checks a alerty
  - [ ] Fáze 8D – izolované smoke/load testy a anonymní demo prostředí
  - [ ] Fáze 8E – go-live checklist, importní startovní bod a závěrečná akceptace

Aktuální stav: Fáze 0–7 a podfáze 8A jsou dokončené. Fáze 8 zůstává rozpracovaná; 8B–8E nebyly zahájeny. Legacy mapování bylo ověřeno proti živé databázi a přijato ve Fázi 1F. Fáze 2 implementovala zakázkovou a skladovou repasi s paralelními větvemi, Fáze 3 atomické hromadné zásilky AUTOKLUBY ↔ POLSKO, Fáze 4 vratky a finance, Fáze 5 diskusi a notifikace a Fáze 6 opakovatelné reklamace `WARRANTY` a systémové výkupy `BUYBACK`.

Pohoda bridge z Fáze 7 je nasazený a běží každých pět minut. Použitelný základní snapshot je `PARTIAL`: 2482 platných `RR` referencí je přijato jako `UNMAPPED` a dvě duplicitní reference zůstávají bezpečně odmítnuté. Následné nezměněné běhy používají `UNCHANGED_CONFIRMATION_ONLY`. HPSERVER konektor má pouze read-only SQL přístup k Pohodě a portál nemá přímé SQL spojení do Pohody.

`PRODUCT_REPRICING = DEFERRED_EXTERNAL_SYSTEM`. Nákupní ani prodejní ceny se přes Pohoda bridge nezapisují a produkční command whitelist zůstává prázdný. `LEGACY_SHOP_IMPORT_ENABLED=false` zůstává bezpečná provozní i výchozí hodnota.

Read-only produkční kontrola 8A je dostupná jako `php artisan ops:preflight` nebo `php artisan ops:preflight --json`; podrobnosti jsou v [docs/OPERATIONS_PREFLIGHT.md](docs/OPERATIONS_PREFLIGHT.md). PHP CLI 7.4.33 a Windows Server 2012 R2 konektoru jsou přijatý technický dluh (`WARN`), nikoli blokátor 8A. Produkční aktivace legacy importu, diskusních e-mailů ani zákaznických výkupních e-mailů dosud neproběhla.

## Instalace z čistého klonu

Požadavky: PHP 8.5 s `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `session`, `tokenizer` a `xml`; Composer 2; MySQL 8 nebo podporovaná MariaDB.

```bash
git clone https://github.com/nightstarter/portal-repaserizeni-cz.git
cd portal-repaserizeni-cz
composer install
cp .env.example .env
php artisan key:generate
cp .env.testing.example .env.testing
chmod 600 .env.testing
php artisan key:generate --env=testing
```

Vyplňte pouze lokální `.env`, vytvořte prázdnou databázi a pak:

```bash
php artisan migrate --seed
php artisan portal:create-admin
composer test
```

Příkaz pro prvního administrátora přijímá heslo skrytě interaktivně, aby se nedostalo do shell historie. Další účty vytváří přihlášený `AK_ADMIN`.

Webový server musí obsluhovat výhradně adresář `public/`; příklad a provozní postup jsou v `docs/DEPLOYMENT.md`.

## Bezpečnostní zásady Fáze 0

- každá osoba má vlastní účet a serverově ověřovanou roli,
- deaktivovaný účet nebo organizace se nepřihlásí,
- přihlášení je rate-limitované a session se po něm regeneruje,
- hesla používají frameworkový Argon2id hash,
- změny uživatelů, přihlášení a preference jazyka se auditují bez hesel,
- `.env`, logy a závislosti se necommitují,
- health endpoint `/up` nevrací citlivé provozní údaje,
- integrační přepínače jsou ve výchozím stavu vypnuté.

## Kontroly kvality

Testy používají výhradně soukromý `.env.testing` se SQLite `:memory:`. `composer test` před startem fail-fast ověří izolaci připojení; ruční `migrate:fresh`, `migrate:refresh`, `migrate:reset` ani `db:wipe` nejsou součástí testovacího postupu.

```bash
composer validate --strict
vendor/bin/pint --test
composer analyse
composer test
```

Stejné kontroly běží v GitHub Actions na PHP 8.5.
