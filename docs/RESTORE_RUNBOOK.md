# Restore runbook

Tento postup je výhradně pro izolovaný test nebo disaster-recovery náhradu. Nikdy neobnovujte do produkční DB ani produkčního `storage/app/private`.

## Příprava

1. Mimo maintenance okno spusťte read-only `php8.5 artisan backup:diagnose --json`. Vyžadujte exit `0`, `schema_only_probe=OK` a `data_dump_probe=OK`. Data probe čte nejvýše jeden řádek pouze z technické tabulky `migrations`, obsah nevrací a vlastní sandbox uklidí; při chybě nic neopravujte automaticky a nepřidávejte DB granty bez schválení DBA.
2. Vyhlaste krátké maintenance okno, ověřte nepřítomnost externích zápisových CLI procesů a spusťte právě jeden `php8.5 artisan backup:create --target=/absolute/new-backup --json`. Cíl musí být nový. Zaznamenejte začátek/konec, backup ID nebo bezpečný error code a exit code. Příkaz sám vrátí aplikaci z Laravel maintenance i při chybě.
3. Při nenulovém exit další create neopakujte. Do incidentu uložte pouze `status`, `code`, `phase`, případný `process_exit_code` a `recommendation`. Podporované fáze jsou `DATABASE_DUMP`, `PRIVATE_ARCHIVE`, `METADATA`, `MANIFEST`, `VERIFY`, `FINALIZE`, `MAINTENANCE` a `UNKNOWN`. Raw stderr, command line, host, DB name, username, DSN a credentials se neevidují. `DUMP_PERMISSION_DENIED` se řeší jen minimálním DBA-schváleným oprávněním a pouze pokud diagnostika prokáže existující logical objekty.
4. Na webserveru spusťte `php8.5 artisan backup:verify /absolute/new-backup --json`. Nenulový exit zastavuje postup.
5. Přeneste kompletní adresář šifrovaným kanálem na úložiště mimo webserver s oddělenými credentials.
6. Na off-server kopii znovu spusťte kompatibilní `backup:verify`; nekopírujte jen manifest.
7. Získejte oddělené secret escrow pouze pro izolovanou obnovu; klíč držte mimo balíček.
8. Zaznamenejte incident/change ID, osoby a začátek restore měření.
9. Připravte nový izolovaný host/namespace, `APP_ENV=restore-test`, nový prázdný DB název, jiný účet a nový prázdný storage adresář mimo produkční storage.
10. Nastavte `BACKUP_PRODUCTION_DB_HOST/PORT/DATABASE` na identitu produkce, aby guard mohl odmítnout shodu. Použijte oddělenou config cache; nekopírujte produkční cache.
11. Ověřte `LEGACY_SHOP_IMPORT_ENABLED=false`, `DISCUSSION_EMAIL_ENABLED=false`, `BUYBACK_CUSTOMER_EMAIL_ENABLED=false`, `POHODA_BRIDGE_ENABLED=false`, prázdný command whitelist a `PRODUCT_REPRICING=DEFERRED_EXTERNAL_SYSTEM`. Neinstalujte cron ani Task Scheduler.

## Obnova a kontrola

1. Standardním MariaDB klientem obnovte dump do **nové** DB. Credentials patří do environmentu/chráněného klientského souboru, nikdy do argumentu procesu.
2. Nespouštějte `migrate`, `migrate:fresh`, `migrate:refresh` ani seed. Obnovené migrace jsou předmětem kontroly.
3. Spusťte:

   ```bash
   php artisan backup:restore-check /absolute/backup --target=/absolute/new-empty-private --json
   ```

4. Nenulový exit znamená neúspěšný restore test. Nástroj při vlastní chybě uklidí jen jím vytvořený sandbox/soubory; cizí či nejasné cesty nemaže.
5. Úspěch dokládá shodu migrací a kritických počtů, FK bez porušení, povinné unique indexy, checksum každého dokumentu/přílohy, existenci všech DB referencí a nulové orphan soubory podle přísné politiky „private soubor musí mít DB referenci“.
6. Technickým izolovaným účtem ověřte login a základní read-only dashboard/detail bez zobrazení či exportu PII do protokolu. Ověřte, že scheduler, SMTP, import a bridge neběží.
7. Zaznamenejte konec, naměřené časy create/přenos/verify/restore, backup ID a výsledek. Vlastník následně potvrdí RPO a RTO. Obsah dokumentů ani PII do evidence nekopírujte.

Úspěšný test se eviduje pouze u skutečného produkčního backup ID. PHPUnit fixture dokládá funkci guardů, nikoli obnovitelnost produkce.
