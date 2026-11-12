# Portál repase řízení

Samostatný transakční portál AUTOKLUBY ↔ POLSKO. Procesní pravidla určuje `transakcni_system_repase_MASTER_v17.md`, technickou implementaci `transakcni_system_repase_IMPLEMENTACE_v18.md`.

## Stav implementace

- [x] Fáze 0 – bootstrap a bezpečný základ (viz `docs/PHASE_0.md`)
- [ ] Fáze 1 – import a doménové jádro (Fáze 1A: datové jádro a read-only UI dokončeno; viz `docs/PHASE_1A.md`)
- [ ] Fáze 2 – základní workflow repase
- [ ] Fáze 3 – zásilky a hromadné skenování
- [ ] Fáze 4 – vratky a finance
- [ ] Fáze 5 – diskuse a notifikace
- [ ] Fáze 6 – reklamace a výkup
- [ ] Fáze 7 – Pohoda bridge a přecenění
- [ ] Fáze 8 – produkční připravenost

Produkční import objednávek není ve Fázi 1A implementován a `LEGACY_SHOP_IMPORT_ENABLED=false` zůstává bezpečná výchozí hodnota.

## Instalace z čistého klonu

Požadavky: PHP 8.5 s `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `session`, `tokenizer` a `xml`; Composer 2; MySQL 8 nebo podporovaná MariaDB.

```bash
git clone https://github.com/nightstarter/portal-repaserizeni-cz.git
cd portal-repaserizeni-cz
composer install
cp .env.example .env
php artisan key:generate
```

Vyplňte pouze lokální `.env`, vytvořte prázdnou databázi a pak:

```bash
php artisan migrate --seed
php artisan portal:create-admin
php artisan test
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

```bash
composer validate --strict
vendor/bin/pint --test
composer analyse
php artisan test
```

Stejné kontroly běží v GitHub Actions na PHP 8.5.
