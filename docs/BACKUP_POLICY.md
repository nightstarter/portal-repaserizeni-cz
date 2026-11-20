# Backup policy

## Povinný inventář

Jedna obnovitelná sada zahrnuje:

1. konzistentní dump MariaDB databáze portálu;
2. celý `storage/app/private` ve stejném konzistenčním bodu;
3. produkční `.env` a `APP_KEY` v **samostatném chráněném secret escrow**, nikdy v běžném archivu ani Gitu;
4. Apache/PHP konfiguraci nutnou k obnově;
5. export cron definice webserveru;
6. export Windows Task Scheduler úlohy;
7. chráněný HPSERVER connector bundle: `config/config.local.php`, `var/state`, provozní konfigurace; běžné logy a spool jen při incidentní potřebě;
8. nasazený Git commit/tag;
9. verze PHP, MariaDB a connectoru, včetně přijatého PHP 7.4.33 / Windows Server 2012 R2 dluhu.

Apache/PHP, cron, Task Scheduler a connector konfigurace mohou obsahovat citlivé hodnoty. Do backup sady vstupují pouze jako archiv zašifrovaný dokumentovaným externím nástrojem (například organizací spravované `age`/GPG). Klíč zůstává mimo repozitář, archiv a webserver. Portál nevytváří vlastní kryptografii.

Nezálohují se `vendor`, cache, sessions, temporary soubory, testovací artefakty, běžné aplikační logy podle retenční politiky ani běžný connector spool. Závislosti se obnovují z lock souboru a nasazené Git revize.

## Manifest formátu 1

`manifest.json` obsahuje pouze provozní metadata: `format_version`, unikátní `backup_id`, `created_at_utc`, `environment`, `status`, `git_commit`, seznam migrací a fingerprint, DB engine/verzi, jednosměrný SHA-256 fingerprint zdrojové DB identity bez credentials, potvrzení povinných komponent, runtime verze, kritické počty a seznam částí (`kind`, relativní `path`, `size`, `sha256`, u private souboru bezpečnou relativní `source_path` a mode).

Kritické počty pokrývají minimálně `organizations`, `users`, `orders`, `items`, `process_threads`, `shipments`, `documents`, `messages` a `message_attachments`. Manifest neobsahuje názvy/obsah zákazníků, e-maily, hesla, APP_KEY, HMAC/SMTP secret ani DSN. `operations-config` musí být označen jako externě šifrovaný.

`FAILED` sada není obnovitelná. `COMPLETE` lze použít až po `backup:verify`, který ověří každou část podle obsahu, nikoli přípony. Existing manifest se nesmí tiše přepisovat a backup cíl musí být explicitní absolutní canonical cesta mimo `/`, repozitář, `public` a celou aplikační storage.

## Vytvoření balíčku

Podporovaná syntaxe je:

```bash
php8.5 artisan backup:create --target=/backup-root/portal-backup-YYYYMMDDTHHMMSSZ --json
```

Finální cesta nesmí existovat. Parent musí být canonical adresář bez symlinku. Přímé `/tmp` se odmítá; pod `/tmp` je pro nouzový test povolen pouze unikátní podadresář `portal-backup-*`, který stále není off-server kopií.

Balíček má přesně tuto základní strukturu:

```text
portal-backup-…/
├── database.sql
├── private-storage.tar
├── metadata.json
└── manifest.json
```

`database.sql` vzniká přes `mariadb-dump --single-transaction --quick --skip-lock-tables` s routines, triggers, events a hex-blob. Heslo je pouze v náhodném klientském option file režimu `0600`; nikdy není argumentem procesu a soubor se maže ve `finally`. `private-storage.tar` obsahuje soubory private storage bez symlinků a manifest eviduje velikost, SHA-256 a mode každého archive entry. `metadata.json` obsahuje jen backup ID, UTC, Git commit, PHP/MariaDB/connector verze, migrace, kritické počty a počet private souborů.

Příkaz nejprve pracuje v skrytém sourozeneckém `.staging-<UUID>` adresáři. V produkci zapne Laravel maintenance, vyčká drain interval, porovná DB/migrace/private inventář před a po, ověří dokumentové reference a strict orphan policy a stejným verifierem jako `backup:verify` předověří všechny části proti neveřejnému nekompletnímu manifestu. Teprve potom atomicky zapíše finální `COMPLETE` manifest a celý staging znovu ověří. Finální adresář vznikne až atomickým rename ověřeného stagingu. Chyba zanechá finální cestu neexistující a smaže pouze vlastní staging.

Laravel maintenance nezastavuje externí CLI writery. Provozovatel proto musí během krátkého maintenance okna ručně zajistit nepřítomnost zápisových schedulerů/workerů. Příkaz žádný cron ani Task Scheduler nemění. APP_KEY, `.env`, DB/SMTP/HMAC hesla, Apache/PHP konfigurace, cron export, Task Scheduler export a chráněná connector konfigurace/state patří do samostatného šifrovaného provozního escrow; nejsou součástí běžného create balíčku ani Gitu.

Při každé chybě vrací create nenulový exit, nezveřejní COMPLETE balíček a uklidí pouze vlastní staging a credentials file. Výstup databázového nástroje se nepropaguje, aby nemohl zveřejnit DSN nebo secret.

## Frekvence, retence a ochrana

- doporučení: denní DB + private storage;
- návrh retence: 7 denních, 4 týdenní a 6 měsíčních kopií;
- alespoň jedna kopie mimo webserver a oddělené přístupové údaje;
- šifrování při přenosu a uložení spravovaným externím nástrojem;
- kontrola checksumů po vytvoření a pravidelně během retence;
- čtvrtletní izolovaný restore test;
- automatické mazání starých záloh zatím není aktivováno.

RPO: `OWNER_CONFIRMATION_REQUIRED`. RTO: `OWNER_CONFIRMATION_REQUIRED`. Doporučení ani testovací čas nejsou potvrzené cíle.
