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
- [ ] Fáze 4 – vratky a finance
- [ ] Fáze 5 – diskuse a notifikace
- [ ] Fáze 6 – reklamace a výkup
- [ ] Fáze 7 – Pohoda bridge a přecenění
- [ ] Fáze 8 – produkční připravenost

Legacy mapování bylo ověřeno proti živé databázi a přijato ve Fázi 1F. Fáze 2A–2B implementovaly doménu a UI zakázkové repase. Fáze 2C–2D přidaly doménové skladové rozhodnutí, paralelní větve `STOCK_REQUEST`, `STOCK_REPLENISHMENT`, čekající `CORE_RETURN`, oddělené fyzické kusy a jejich bezpečné dashboardy, detail, časovou osu a mobilní QR obsluhu. Fáze 2E celý rozsah automatizovaně přijala. Fáze 3A přidala atomickou doménu hromadných zásilek AUTOKLUBY ↔ POLSKO s aktivními rezervacemi fyzických kusů, Fáze 3B její dvojjazyčné UI, hromadný QR příjem a bezpečné expediční akce a Fáze 3C rozsah přijala 272 testy / 904 assertions. Read-only audit ukázal migrace 000001–000006 jako aplikované a žádnou pending migraci; 3C produkční DB nezměnila. Produkční import, Pohoda bridge ani portalový scheduler nebyly aktivovány; `LEGACY_SHOP_IMPORT_ENABLED=false` zůstává bezpečná provozní i výchozí hodnota. Finance, detailní vratky, zákaznické přepravy, dokumenty, diskuse a notifikace budou řešeny až v dalších fázích.

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
