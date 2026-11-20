# Fáze 8B – zálohy, ověření a bezpečný test obnovy

Datum implementace: 20. 11. 2026. Implementace je připravená, ale Fáze 8B **není dokončená ani produkčně akceptovaná**. Fáze 8C–8E nebyly zahájeny.

## Dodané části

- přesný inventář a retenční návrh v [BACKUP_POLICY.md](BACKUP_POLICY.md);
- manifest formátu 1 s UTC časem, backup ID, prostředím, Git revizí, migracemi, DB engine/verzí, povinnými komponentami, velikostmi, SHA-256 a počty kritických tabulek;
- `php artisan backup:plan [--json]`, který pouze vypíše plán;
- `php artisan backup:create --target=<nová-absolutní-cesta> [--json]`, který přes chráněný staging vytvoří a interně ověří balíček;
- `php artisan backup:diagnose [--json]`, který bez exportu řádků ověří dump klienta, protected client config, PDO, logical DB objekty a minimální schema-only probe;
- `php artisan backup:verify <absolutní-cesta> [--json]`, který ověří metadata, canonical cesty, všechny soubory, velikosti a checksumy;
- `php artisan backup:restore-check <absolutní-cesta> --target=<nový-prázdný-adresář> [--json]` pro kontrolu již obnovené izolované DB a obnovu/ověření private souborů;
- preflight kontroly `latest_backup` a `recovery_evidence`;
- restore a disaster-recovery postupy v [RESTORE_RUNBOOK.md](RESTORE_RUNBOOK.md) a [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md).

## Bezpečnostní hranice

Restore-check odmítá `APP_ENV=production`, produkční DB identitu, SQLite test mimo `:memory:`, produkční private storage, webroot/repozitář, root, symlink escape, neprázdný cíl, neplatný či nekompletní manifest a checksum mismatch. Chyby nevypisují interní exception detail. Integrace, SMTP, import, bridge commandy a přecenění musí být vypnuté.

Nástroj neprovádí DB import. Provozovatel obnoví dump standardním MariaDB klientem do nové DB s odlišným názvem a credentials pouze přes chráněné prostředí nebo klientský soubor režimu `0600`; potom spustí restore-check. Produkční DB ani produkční private storage nejsou cílem žádného postupu.

`backup:create` je jediná podporovaná cesta pro vytvoření manifestu kompatibilního s verifierem. V produkci automaticky aktivuje Laravel maintenance mode, vyčká `BACKUP_REQUEST_DRAIN_SECONDS`, vytvoří InnoDB konzistentní dump, TAR private storage a sanitizovaná metadata, zkontroluje DB↔storage vazby a stav před/po a předověří všechny části proti neveřejnému nekompletnímu manifestu. Teprve potom atomicky zapíše finální COMPLETE manifest, ověří celý staging stejným verifierem a atomicky zveřejní finální adresář. `finally` vrací aplikaci z maintenance, pokud jej příkaz sám zapnul.

Maintenance mode neřídí cizí CLI procesy. Provozovatel musí v krátkém okně zajistit, že žádný scheduler/worker/import/ruční command nepíše do portálové DB ani private storage. Příkaz sám cron ani Task Scheduler nemění. Zjištěná změna počtů, migrací či private inventáře vytvoření zastaví.

## Diagnostická oprava dump runneru

Původní runner bezpodmínečně přidával `--routines`, `--events` a `--triggers` a všechny chyby procesu i pozdějších fází převáděl na jednu obecnou hlášku. Raw stderr ani bezpečný exit důvod se neuchoval, takže přesnou historickou příčinu neúspěšných plných běhů nelze zpětně rekonstruovat. Produkční read-only diagnostika 21. 11. 2026 ověřila klient i server MariaDB 10.11.14, TCP/PDO spojení, mode-0600 client config a schema-only dump. Databáze viditelná aplikačnímu účtu obsahovala 0 triggerů, 0 routines a 0 events. Samostatný schema-only probe se starými speciálními volbami i `--result-file` uspěl; tyto volby proto nejsou prokázanou příčinou původního pádu.

Opravený runner staví argv jako pole a drží `--defaults-extra-file` jako první option po binárce. Dump streamuje stdout přímo do mode-0600 `database.sql`, zachytí exit code/timeout a stderr používá pouze interně ke klasifikaci; raw text nikdy nevypíše. Vždy používá `--single-transaction`, `--quick`, `--skip-lock-tables` a MariaDB-10.11 kompatibilní `--no-tablespaces`. `--routines`/`--events`/`--triggers` zapíná jen podle read-only `information_schema` inventáře; při nulovém inventáři používá `--skip-triggers` a tuto skutečnost ukládá do metadata/manifestu. Existující objekty se nesmí vynechat: schema probe nebo plný dump při nedostatku práv skončí bezpečným `DUMP_PERMISSION_DENIED`.

Bezpečné chyby jsou `DUMP_BINARY_NOT_EXECUTABLE`, `DUMP_PROCESS_START_FAILED`, `DUMP_AUTHENTICATION_FAILED`, `DUMP_CONNECTION_FAILED`, `DUMP_PERMISSION_DENIED`, `DUMP_UNSUPPORTED_OPTION`, `DUMP_TIMEOUT`, `DUMP_OUTPUT_WRITE_FAILED` a `DUMP_FAILED_UNKNOWN`. Výstup smí obsahovat jen kód, fázi, exit code, basename binárky a obecné doporučení, nikdy raw stderr, host, username, heslo ani DSN.

## Stav akceptace

Automatizovaně lze ověřit formát manifestu, checksumy, guardy, migrace, počty, FK/indexy, DB↔storage konzistenci, orphan policy a bezpečně vypnuté integrace. V tomto běhu vznikly pouze testovací fixture archivy v dočasném adresáři; žádný není produkční důkaz.

Před dokončením 8B musí provozovatel doložit:

1. skutečnou produkční COMPLETE zálohu;
2. kopii mimo webserver;
3. úspěšné ověření všech checksumů;
4. úspěšnou obnovu do izolované neprodukční DB a storage;
5. naměřenou dobu zálohy a obnovy;
6. vlastníkem potvrzené RPO a RTO (`OWNER_CONFIRMATION_REQUIRED`).

Do té doby musí `latest_backup` bez skutečného manifestu zůstat `ERROR`. PHP 7.4.33 a Windows Server 2012 R2 se nemění.
