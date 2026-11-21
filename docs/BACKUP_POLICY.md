# Backup policy

## Povinný inventář

Jedna obnovitelná sada zahrnuje:

1. konzistentní dump MariaDB databáze portálu;
2. doménová data `storage/app/private` ve stejném konzistenčním bodu, kromě dvou níže uvedených verzovaných provozních artefaktů;
3. produkční `.env` a `APP_KEY` v **samostatném chráněném secret escrow**, nikdy v běžném archivu ani Gitu;
4. Apache/PHP konfiguraci nutnou k obnově;
5. export cron definice webserveru;
6. export Windows Task Scheduler úlohy;
7. chráněný HPSERVER connector bundle: `config/config.local.php`, `var/state`, provozní konfigurace; běžné logy a spool jen při incidentní potřebě;
8. nasazený Git commit/tag;
9. verze PHP, MariaDB a connectoru, včetně přijatého PHP 7.4.33 / Windows Server 2012 R2 dluhu.

Apache/PHP, cron, Task Scheduler a connector konfigurace mohou obsahovat citlivé hodnoty. Do backup sady vstupují pouze jako archiv zašifrovaný dokumentovaným externím nástrojem (například organizací spravované `age`/GPG). Klíč zůstává mimo repozitář, archiv a webserver. Portál nevytváří vlastní kryptografii.

Nezálohují se `vendor`, cache, sessions, temporary soubory, testovací artefakty, běžné aplikační logy podle retenční politiky ani běžný connector spool. Závislosti se obnovují z lock souboru a nasazené Git revize.

Private storage má uzavřenou aplikační politiku přesně dvou vyloučených relativních cest: `.gitignore` (`DEPLOYMENT_CONTROL_FILE`) a `legacy-shop-phase1c-report.json` (`GENERATED_DIAGNOSTIC_ARTIFACT`). Oba soubory zůstávají ve zdrojovém private storage beze změny, nevstupují do TAR, nevyžadují DB referenci a běžný restore je neobnovuje. Seznam je konstantou v kódu, není konfigurovatelný přes `.env`. Wildcard ani podobný název neplatí; nested varianta, kopie, `.gitignore.bak`, jiný report nebo jakýkoli neznámý orphan zůstává chybou `PRIVATE_INVENTORY_MISMATCH`.

## Manifest formátu 1

`manifest.json` obsahuje pouze provozní metadata: `format_version`, unikátní `backup_id`, `created_at_utc`, `environment`, `status`, `git_commit`, seznam migrací a fingerprint, DB engine/verzi, jednosměrný SHA-256 fingerprint zdrojové DB identity bez credentials, potvrzení povinných komponent, runtime verze, kritické počty, explicitní seznam vyloučených provozních artefaktů s klasifikací a seznam částí (`kind`, relativní `path`, `size`, `sha256`, u private souboru bezpečnou relativní `source_path` a mode).

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

`database.sql` vzniká přes `mariadb-dump --single-transaction --quick --skip-lock-tables --no-tablespaces` s hex-blob a s logical objekty pouze podle ověřeného inventáře. Heslo je pouze v náhodném klientském option file režimu `0600`; nikdy není argumentem procesu a soubor se maže ve `finally`. `private-storage.tar` obsahuje doménové soubory private storage bez symlinků a bez dvou přesných provozních exclusions; manifest eviduje velikost, SHA-256 a mode každého archive entry. `metadata.json` obsahuje jen backup ID, UTC, Git commit, PHP/MariaDB/connector verze, migrace, kritické počty, dump volby, verzovanou exclusion policy a počet archivovaných private souborů.

Runner přidává `--no-tablespaces`, aby běžný logický backup nepotřeboval globální tablespace metadata. `--routines`, `--events` a `--triggers` se přidají pouze tehdy, když read-only inventář `information_schema` najde příslušné objekty; nulové počty a použité bezpečné volby jsou součástí manifestových metadat. Pokud objekty existují, schema-only diagnostika musí projít s jejich volbami. Chybějící oprávnění je `DUMP_PERMISSION_DENIED`; nástroj práva nepřidává a objekty potichu nevynechá. Minimální konkrétní granty musí schválit DBA podle nalezených objektů; typicky jde vedle tabulkového `SELECT`/`SHOW VIEW` o `TRIGGER`, `EVENT` a oprávnění ke čtení definic routines.

Před produkčním create spusťte `php8.5 artisan backup:diagnose --json`. Příkaz vytvoří jen vlastní dočasný sandbox a mode-0600 client config, ověří PDO a verze, provede `information_schema` inventář a `mariadb-dump --no-data`; neexportuje řádky ani private storage a vše uklidí ve `finally`. Nenulový exit zastavuje postup.

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
