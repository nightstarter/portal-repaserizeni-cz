# Restore runbook

Tento postup je výhradně pro izolovaný test nebo disaster-recovery náhradu. Nikdy neobnovujte do produkční DB ani produkčního `storage/app/private`.

## Příprava

1. Mimo maintenance okno spusťte read-only `php8.5 artisan backup:diagnose --json`. Vyžadujte exit `0`, `schema_only_probe=OK` a `data_dump_probe=OK`. Data probe čte nejvýše jeden řádek pouze z technické tabulky `migrations`, obsah nevrací a vlastní sandbox uklidí; při chybě nic neopravujte automaticky a nepřidávejte DB granty bez schválení DBA.
2. Vyhlaste krátké maintenance okno, ověřte nepřítomnost externích zápisových CLI procesů a spusťte právě jeden `php8.5 artisan backup:create --target=/absolute/new-backup --json`. Cíl musí být nový. Zaznamenejte začátek/konec, backup ID nebo bezpečný error code a exit code. Příkaz sám vrátí aplikaci z Laravel maintenance i při chybě.
3. Při nenulovém exit další create neopakujte. Do incidentu uložte pouze `status`, `code`, `phase`, případný `process_exit_code` a `recommendation`. Podporované fáze jsou `DATABASE_DUMP`, `PRIVATE_ARCHIVE`, `METADATA`, `MANIFEST`, `VERIFY`, `FINALIZE`, `MAINTENANCE` a `UNKNOWN`. Raw stderr, command line, host, DB name, username, DSN a credentials se neevidují. `DUMP_PERMISSION_DENIED` se řeší jen minimálním DBA-schváleným oprávněním a pouze pokud diagnostika prokáže existující logical objekty.
   U `VERIFY_FAILED` evidujte také bezpečný `verification_code` a případný relativní `component`. Kódy jsou popsány v `docs/PHASE_8B.md`; nikdy do incidentu nekopírujte absolutní staging cestu, checksum ani obsah komponenty. Přesné kořenové cesty `.gitignore` a `legacy-shop-phase1c-report.json` jsou provozní exclusions evidované v manifestu, ale nejsou součástí TAR a restore je nevytváří. Všechny doménové i podobně pojmenované soubory zůstávají pod strict DB↔storage kontrolou.
4. Na webserveru spusťte `php8.5 artisan backup:verify /absolute/new-backup --json`. Nenulový exit zastavuje postup.
5. Přeneste kompletní adresář šifrovaným kanálem na úložiště mimo webserver s oddělenými credentials.
6. Na off-server kopii znovu spusťte kompatibilní `backup:verify`; nekopírujte jen manifest.
7. Až po skutečném nezávislém verify celé off-server kopie potvrďte tuto ruční skutečnost nad přesným původním balíčkem:

   ```bash
   php8.5 artisan backup:evidence:off-server /absolute/original-backup \
     --evidence-id=CHG-NEBO-INCIDENT-ID --confirmed --json
   ```

   Příkaz vzdálenou kopii sám nekontroluje. Neukládejte do evidence ID cestu, credentials, jméno zákazníka ani jinou PII. Stejný ID lze bezpečně zopakovat; jiný konfliktní ID se odmítne.
8. Získejte oddělené secret escrow pouze pro izolovanou obnovu; klíč držte mimo balíček i `recovery-evidence.json`.
9. Zaznamenejte incident/change ID, osoby a začátek restore měření.
10. Připravte nový izolovaný host/namespace, `APP_ENV=restore-test`, nový prázdný DB název, jiný účet a nový prázdný storage adresář mimo produkční storage.
11. Nastavte `BACKUP_PRODUCTION_DB_HOST/PORT/DATABASE` na identitu produkce, aby guard mohl odmítnout shodu. Použijte oddělenou config cache; nekopírujte produkční cache.
12. Ověřte `LEGACY_SHOP_IMPORT_ENABLED=false`, `DISCUSSION_EMAIL_ENABLED=false`, `BUYBACK_CUSTOMER_EMAIL_ENABLED=false`, `POHODA_BRIDGE_ENABLED=false`, prázdný command whitelist a `PRODUCT_REPRICING=DEFERRED_EXTERNAL_SYSTEM`. Neinstalujte cron ani Task Scheduler.

## Obnova a kontrola

1. Standardním MariaDB klientem obnovte dump do **nové** DB. Credentials patří do environmentu/chráněného klientského souboru, nikdy do argumentu procesu.
2. Nespouštějte `migrate`, `migrate:fresh`, `migrate:refresh` ani seed. Obnovené migrace jsou předmětem kontroly.
3. Spusťte:

   ```bash
   php artisan backup:restore-check /absolute/backup \
     --target=/absolute/new-empty-private \
     --evidence-id=CHG-NEBO-INCIDENT-ID \
     --json
   ```

4. Nenulový exit znamená neúspěšný restore test a nikdy nezapíše `SUCCESS`. Nástroj při vlastní chybě uklidí jen jím vytvořený sandbox/soubory; cizí či nejasné cesty nemaže.
5. Úspěch dokládá shodu migrací a kritických počtů, FK bez porušení, povinné unique indexy, checksum každého dokumentu/přílohy, existenci všech DB referencí a nulové orphan soubory podle přísné politiky „private soubor musí mít DB referenci“.
6. Technickým izolovaným účtem ověřte login a základní read-only dashboard/detail bez zobrazení či exportu PII do protokolu. Ověřte, že scheduler, SMTP, import a bridge neběží.
7. Po úspěchu zkontrolujte `backup:verify --json`: validita zálohy musí zůstat nezávisle `true` a `recovery_evidence` musí být `OK`. Sidecar uchovává append-only historii dalších restore testů; původní manifest a tři komponenty se nikdy neupravují.
8. Zaznamenejte konec, naměřené časy create/přenos/verify/restore, backup ID a výsledek. Vlastník následně potvrdí RPO a RTO. Obsah dokumentů ani PII do evidence nekopírujte.

Úspěšný test se eviduje pouze u skutečného produkčního backup ID. PHPUnit fixture dokládá funkci guardů, nikoli obnovitelnost produkce.

Ztráta `recovery-evidence.json` je ztráta provozního důkazu a preflight `WARN`, nikoli poškození samotné zálohy. Ruční editace sidecaru ani manifestu není podporovaná; porušený podpis nebo vazba na jiné backup ID/manifest SHA-256 je preflight `ERROR`.
