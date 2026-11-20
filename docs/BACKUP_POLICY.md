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

`FAILED` sada není obnovitelná. `COMPLETE` lze použít až po `backup:verify`, který ověří každou část podle obsahu, nikoli přípony. Existing manifest se nesmí tiše přepisovat a backup cíl musí být explicitní absolutní canonical cesta mimo `/`, repozitář, `public` a private storage.

Pro MariaDB dump použijte klientský option file režimu `0600` mimo archiv a po běhu jej bezpečně odstraňte. Příklad tvaru příkazu je `mariadb-dump --defaults-extra-file=/secure/runtime/client.cnf --single-transaction --quick --routines --triggers <database>`. Heslo nesmí být argument ani být vidět v process listu. Skutečný provozní wrapper musí při každé chybě ponechat status `FAILED`, vrátit nenulový exit a uklidit pouze vlastní dočasné soubory.

## Frekvence, retence a ochrana

- doporučení: denní DB + private storage;
- návrh retence: 7 denních, 4 týdenní a 6 měsíčních kopií;
- alespoň jedna kopie mimo webserver a oddělené přístupové údaje;
- šifrování při přenosu a uložení spravovaným externím nástrojem;
- kontrola checksumů po vytvoření a pravidelně během retence;
- čtvrtletní izolovaný restore test;
- automatické mazání starých záloh zatím není aktivováno.

RPO: `OWNER_CONFIRMATION_REQUIRED`. RTO: `OWNER_CONFIRMATION_REQUIRED`. Doporučení ani testovací čas nejsou potvrzené cíle.
