# Restore runbook

Tento postup je výhradně pro izolovaný test nebo disaster-recovery náhradu. Nikdy neobnovujte do produkční DB ani produkčního `storage/app/private`.

## Příprava

1. Zaznamenejte incident/change ID, backup ID, začátek měření a osoby provádějící kontrolu.
2. Získejte kopii backup sady mimo webserver; secret escrow a dešifrovací klíč držte odděleně.
3. Spusťte `php artisan backup:verify /absolute/backup --json`. Jakýkoli nenulový exit zastavuje obnovu.
4. Připravte nový izolovaný host/namespace, `APP_ENV=restore-test`, nový prázdný DB název, jiný účet a nový prázdný storage adresář mimo produkční storage.
5. Nastavte `BACKUP_PRODUCTION_DB_HOST/PORT/DATABASE` na identitu produkce, aby guard mohl odmítnout shodu. Použijte oddělenou config cache; nekopírujte produkční cache.
6. Ověřte `LEGACY_SHOP_IMPORT_ENABLED=false`, `DISCUSSION_EMAIL_ENABLED=false`, `BUYBACK_CUSTOMER_EMAIL_ENABLED=false`, `POHODA_BRIDGE_ENABLED=false`, prázdný command whitelist a `PRODUCT_REPRICING=DEFERRED_EXTERNAL_SYSTEM`. Neinstalujte cron ani Task Scheduler.

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
7. Zaznamenejte konec a naměřené časy zálohy i obnovy. Obsah dokumentů ani PII do evidence nekopírujte.

Úspěšný test se eviduje pouze u skutečného produkčního backup ID. PHPUnit fixture dokládá funkci guardů, nikoli obnovitelnost produkce.
