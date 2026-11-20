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
| `connector_runtime_debt` | — | PHP 7.4.33 + Windows 2012 R2 | — |

`UNMAPPED` RR reference není chyba. Čerstvý `PARTIAL` run s 2482 accepted a dvěma `DUPLICATE_PRODUCT_REFERENCE` je známý použitelný stav. Čerstvost heartbeat/snapshotu nastavují `OPS_HEARTBEAT_STALE_MINUTES` a `OPS_SNAPSHOT_STALE_MINUTES`.

## JSON a ochrana údajů

JSON obsahuje jen `status`, seznam `checks`, bezpečné číselné/booleovské contexty a obecné zprávy. Neobsahuje konfiguraci tajemství, DSN, hostname, SQL, `.env`, PII ani interní exception. Kontrola SMTP pouze zjišťuje přítomnost potřebné konfigurace; nenavazuje spojení.

Stejnou službu používá read-only `/admin/operations/preflight`, dostupný pouze `AK_ADMIN` z AUTOKLUBY. Ostatní dostanou `404`. Stránka nemá opravná tlačítka ani mutační endpoint.

## Ruční kontrola

Portál nevidí Windows Task Scheduler API. Provozovatel musí na HPSERVERU zkontrolovat Last Run Result `0x0`, poslední běh, pětiminutový interval, „do not start a new instance“, servisní účet, Windows Time, spool a ACL. Preflight tento stav nikdy nepředstírá jako automaticky ověřený.
