# Nasazení Pohoda bridge na HPSERVER — připravený postup

Tento dokument je runbook pro pozdější kontrolované nasazení. Fáze 7B žádný krok na HPSERVERU ani produkčním portálu neprovedla.

## Předpoklady a záloha

1. Ověřte zálohu portálové DB a konfigurace aplikace.
2. Zálohujte případný stávající adresář konektoru včetně `config.local.php`, `var\state.json` a celého pending spoolu. Secret nezahrnujte do běžného ticketu/logu.
3. Ověřte Windows Time, DNS a odchozí TCP/443. Nevytvářejte firewall inbound pravidlo.
4. Vytvořte dedikovaný neinteraktivní servisní účet a read-only SQL účet s minimálním `SELECT` na `dbo.SKz`.

## Portál nejprve vypnutý

1. Nasaďte aplikační kód.
2. Aplikujte migrace 000014, 000015 a 000016 standardním kontrolovaným postupem.
3. Ponechte `POHODA_BRIDGE_ENABLED=false`, nastavte serverový allowlist `POHODA_BRIDGE_PRODUCT_PREFIXES=RR` a `POHODA_BRIDGE_WAREHOUSES=DECIN` a ponechte `LEGACY_SHOP_IMPORT_ENABLED=false`, `DISCUSSION_EMAIL_ENABLED=false` a `BUYBACK_CUSTOMER_EMAIL_ENABLED=false`.
4. Obnovte config/route/view cache a ověřte migration status a `/admin/integrations/pohoda`.

## Instalace konektoru

1. Zkopírujte `connector\pohoda-bridge` do `C:\Services\portal-repase-bridge` mimo webroot.
2. Vytvořte `config\config.local.php` z example; nastavte NTFS ACL podle README.
3. Vygenerujte secret lokálně `php bin\bridge.php key:generate`, vložte Base64 hodnotu do lokální konfigurace a bezpečným kanálem do portálového `.env`.
4. Nastavte `product_scope=sql_prefix`, `product_reference_prefixes=array('RR')`, `quantity_mode=unconfirmed` a `dry_run=true`. Wildcardy do prefixu nevkládejte; `%` přidává konektor jen do bind parametru.
5. Spusťte `diagnose`, `stock:inspect`, `stock:dry-run`, `stock:anomalies`, `heartbeat:dry-run` a `sync:once --dry-run`. Zkontrolujte RR prefix, počty/duplicity/anomálie a že SQL účet nemá INSERT/UPDATE/DELETE/ALTER.
6. Po písemném potvrzení významu zásoby nastavte explicitně `physical` nebo `free`. Nic neodhadujte.

## Kontrolované zapnutí

1. Zapněte portálový flag až po ověření klíče, času a allowlistu.
2. Odešlete heartbeat a jeden nový kontrolovaný snapshot s novým `snapshot_run_id`; ověřte diagnostiku, 5/5 chunků, mapped/unmapped/rejected počty, položkové chyby a stale stav. Starý partial run nemažte ani neupravujte.
3. Nastavte konektoru `dry_run=false`.
4. Teprve poté vytvořte Task Scheduler:

```text
Program:   C:\wamp64\bin\php\php7.4.33\php.exe
Arguments: -d xdebug.mode=off C:\Services\portal-repase-bridge\bin\bridge.php sync:once
Start in:  C:\Services\portal-repase-bridge
Repeat:    every 5 minutes
Policy:    do not start a new instance if one is running
```

Použijte účet bez interaktivního přihlášení a zaznamenávejte návratový kód. Denní diagnostiku neplánujte.

## Rotace klíče

Nastavte nový current pár a starý previous pár na portálu, rebuild config cache, změňte konektor, ověřte heartbeat a snapshot, potom previous odstraňte. Každý HTTP retry má nový nonce, ale snapshot zachovává `snapshot_run_id`, `batch_id` i přesné raw payload bytes.

## Bezpečný rollback

1. Vypněte Task Scheduler a `POHODA_BRIDGE_ENABLED=false`.
2. Neodstraňujte pending spool; uchovejte jej pro analýzu/retry. Po změně scope zůstane neshodný spool bezpečně blokovaný `SPOOL_SCOPE_MISMATCH` a nesmí se ručně přeznačit bez kontroly payloadu.
3. Vraťte předchozí kód konektoru nebo portálu. Neprovádějte slepý schema rollback a nemažte snapshot historii.
4. Pokud je nutná rotace po incidentu, zneplatněte current secret a proveďte celý rotation postup.

## PHP upgrade

PHP 7.4.33 je EOL technický dluh. Ve fázi 8 instalujte izolované PHP CLI 8.2+, ověřte `curl`, `openssl`, `PDO`, `pdo_sqlsrv`, `sqlsrv`, spusťte diagnostiku/parity testy a změňte Task Scheduler cestu až po akceptaci. Webový PHP runtime není důvod sdílet s konektorem.
