# Provozní preflight

Read-only příkaz:

```bash
php artisan ops:preflight
php artisan ops:preflight --json
```

Příkaz nic neopravuje, nespouští migrace/import/snapshot/SMTP/bridge command a nezapisuje do aplikačních tabulek. Exit code je `0`, pokud žádná kontrola nemá `ERROR`; `WARN` samotný je úspěšný exit. Jakýkoli `ERROR` vrací nenulový exit.

## Stavy

- `OK`: kontrola prošla nebo je riziková funkce bezpečně vypnutá.
- `WARN`: provoz může pokračovat, ale existuje přijaté riziko, čerstvostní odchylka nebo nutná ruční kontrola.
- `ERROR`: bezpečný produkční předpoklad chybí; go-live této konfigurace je blokovaný.

## Kontroly a klasifikace

| ID | OK | WARN | ERROR |
| --- | --- | --- | --- |
| `application_environment` | production + debug off | — | jiný stav |
| `https_and_session` | HTTPS + Secure/HttpOnly/SameSite | — | chybějící flag |
| `database` | read-only `SELECT 1` | — | spojení selže |
| `migrations` | všechny soubory aplikované | — | chybí repository / pending |
| runtime adresáře | existují a writable | — | chybí/nejsou writable |
| `private_storage` | private disk mimo `public/` | — | chybí/je pod webrootem |
| `cache` | backend dostupný bez testovacího zápisu | — | nelze ověřit |
| `scheduler` | — | vždy ruční kontrola Task Scheduleru | — |
| `pohoda_heartbeat` | čerstvý `OK` | chybí, stale, degraded | chybí schéma/nelze číst |
| `pohoda_snapshot` | čerstvý COMPLETE nebo známý PARTIAL 2482+2 duplicity | chybí/stale/neznámý PARTIAL | chybí schéma/nelze číst |
| `bridge_runs` | receiving=0, failed=0 | receiving>0 | failed>0 |
| `integration_outbox` | pending=0, failed=0 | pending>0 | failed>0 |
| `feature_flags` | import/e-maily off, repricing deferred | — | odchylka |
| `bridge_command_whitelist` | prázdný | — | neprázdný |
| `bridge_server_allowlists` | obsahuje DECIN a RR | — | chybí |
| `smtp` | funkce off nebo úplná konfigurace | — | funkce on + neúplná konfigurace |
| `disk_space` | nad `OPS_MIN_FREE_DISK_MB` | — | pod limitem/nezjistitelné |
| `latest_backup` | čerstvý produkční COMPLETE manifest a všechny checksumy | — | chybí, není produkční, je starý/neplatný |
| `recovery_evidence` | off-server kopie a čerstvý úspěšný restore test | některý důkaz chybí/je starý | — |
| `connector_runtime_debt` | — | PHP 7.4.33 + Windows 2012 R2 | — |

`UNMAPPED` RR reference není chyba. Čerstvý `PARTIAL` run s 2482 accepted a dvěma `DUPLICATE_PRODUCT_REFERENCE` je známý použitelný stav. Čerstvost heartbeat/snapshotu nastavují `OPS_HEARTBEAT_STALE_MINUTES` a `OPS_SNAPSHOT_STALE_MINUTES`.

## JSON a ochrana údajů

JSON obsahuje jen `status`, seznam `checks`, bezpečné číselné/booleovské contexty a obecné zprávy. Neobsahuje konfiguraci tajemství, DSN, hostname, SQL, `.env`, PII ani interní exception. Kontrola SMTP pouze zjišťuje přítomnost potřebné konfigurace; nenavazuje spojení.

Stejnou službu používá read-only `/admin/operations/preflight`, dostupný pouze `AK_ADMIN` z AUTOKLUBY. Ostatní dostanou `404`. Stránka nemá opravná tlačítka ani mutační endpoint.

## Produkční akceptační výsledek 20. 11. 2026

Po provozní opravě `APP_URL=https://portal.repaserizeni.cz` a obnově config cache byly spuštěny textová i JSON varianta přes `php8.5`. Obě skončily exit code `0`, bez `ERROR`: 18 kontrol bylo `OK` a dvě `WARN`.

Zbývající `WARN` jsou výhradně:

- ruční kontrola Windows Task Scheduleru, protože portál nemá důvěryhodný přístup k jeho Last Run Result;
- přijatý technický dluh PHP CLI 7.4.33 a Windows Server 2012 R2.

Preflight potvrdil čerstvý heartbeat a confirmation, použitelný PARTIAL snapshot 2482 přijatých + 2 známé duplicity, nulové receiving/failed bridge runy, nulové pending/failed integrační a outbox události, bezpečné feature flagy, prázdný command whitelist a HTTPS/session baseline. Tyto výsledky nenahrazují níže uvedenou ruční kontrolu Scheduleru ani živého TLS/vhost/ACL.

## Ruční kontrola

Portál nevidí Windows Task Scheduler API. Provozovatel musí na HPSERVERU zkontrolovat Last Run Result `0x0`, poslední běh, pětiminutový interval, „do not start a new instance“, servisní účet, Windows Time, spool a ACL. Preflight tento stav nikdy nepředstírá jako automaticky ověřený.

## Zálohy a recovery evidence (8B)

`BACKUP_LATEST_MANIFEST` smí ukazovat pouze na absolutní cestu ke skutečné produkční záloze mimo repozitář, webroot a private storage. Testovací fixture se jako produkční důkaz odmítne. Chybějící, nekompletní, starý nebo checksumově neplatný produkční manifest je `ERROR`. Chybějící off-server potvrzení nebo chybějící/starý restore test je samostatný `WARN`, protože samotný archiv ještě nedokládá obnovitelnost.

Výchozí limity jsou 26 hodin pro poslední COMPLETE zálohu a 100 dní pro restore test. Provozovatel je může změnit řízenou produkční konfigurací; tato implementace `.env` nemění. Preflight nevytváří zálohu, nic neobnovuje a nečte obsah dokumentů. Produkční výsledek 8A výše historicky předchází přidání těchto dvou kontrol.
