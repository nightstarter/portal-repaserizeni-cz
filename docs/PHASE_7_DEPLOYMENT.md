# Fáze 7 – deployment a rollback

Toto je kontrolní postup pro další nasazení stejného řešení. V rámci akceptace 7D nebyl žádný krok proveden proti produkci, HPSERVERU, Pohodě ani Task Scheduleru.

## Před nasazením

1. Ověřte obnovitelnou zálohu portálové DB a bezpečnou zálohu konektorového `config.local.php`, `var\state.json` a celého spoolu. Secrets nevkládejte do běžné zálohy/ticketu bez odpovídající ochrany.
2. Zaznamenejte aktuální git hash, migration status, heartbeat/freshness, base run, receiving/failed a Last Run Result.
3. Dočasně zakažte Task Scheduler, aby během změny neběžely nové požadavky. Neodstraňujte spool.
4. Potvrďte read-only SQL oprávnění, Windows Time, odchozí TCP/443 a absenci inbound služby.

## Portál

1. Proveďte standardní push/pull a `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`.
2. Zkontrolujte a kontrolovaně aplikujte rozšiřující migrace 000014, 000015 a 000016. Nikdy nepoužívejte `migrate:fresh` ani slepý rollback schématu.
3. Zachovejte:

```text
POHODA_BRIDGE_ENABLED=true
POHODA_BRIDGE_PRODUCT_PREFIXES=RR
POHODA_BRIDGE_WAREHOUSES=DECIN
LEGACY_SHOP_IMPORT_ENABLED=false
DISCUSSION_EMAIL_ENABLED=false
BUYBACK_CUSTOMER_EMAIL_ENABLED=false
PRODUCT_REPRICING=DEFERRED_EXTERNAL_SYSTEM
```

4. Ověřte current/previous key konfiguraci bez vypsání secretu. Rebuildněte `config:cache`, `route:cache` a `view:cache` a ověřte route list i migration status.
5. Ověřte AK_ADMIN diagnostiku: použitelné PARTIAL/COMPLETE, counts, stale stav, prázdný command whitelist a žádná tajemství.

## Konektor a zapnutí

1. Nasaďte connector do `C:\Services\portal-repase-bridge` bez přepsání lokálního configu, state, spoolu a log ACL.
2. Spusťte `diagnose`, `stock:inspect`, `stock:dry-run`, `stock:anomalies`, `heartbeat:dry-run` a `sync:once --dry-run`. Dry-run nesmí nic odeslat.
3. Potvrďte `product_scope=sql_prefix`, prefix `RR`, warehouse 1/DECIN, `RelSkTyp=1`, `quantity_mode=free`, read-only účet a TLS verification.
4. Task Scheduler musí používat `-d xdebug.mode=off`, interval pět minut a „do not start a new instance“.
5. Scheduler povolte, sledujte jeden běh a ověřte exit 0, heartbeat, freshness a buď jediný confirmation při nezměněných datech, nebo očekávaný nový chunkovaný full přenos při skutečné změně.

## Rollback

1. Vypněte Scheduler. Pokud je nutné odříznout API, nastavte `POHODA_BRIDGE_ENABLED=false` a rebuildněte config cache.
2. Vraťte předchozí aplikační/connector kód, ale zachovejte additive DB schema, config, state, audit a spool. Neprovádějte slepý `migrate:rollback`.
3. Pokud novější kód již vytvořil data, ověřte kompatibilitu předchozí aplikace; jinak ponechte bridge vypnutý do opravy.
4. Po rollbacku spusťte diagnose/dry-run, obnovte scheduler až po kontrole a sledujte heartbeat/freshness.

## Akceptační body po nasazení

- HTTPS+HMAC funguje, HTTP je odmítnuto, nonce replay selže a rotace current/previous je připravena.
- Last Run Result je `0x0`, heartbeat i confirmation se obnovují a `receiving=0`, `failed=0`.
- Nezměněný běh nevytvoří nový run/items; změněný dataset vytvoří nový full přenos.
- PARTIAL zůstává PARTIAL, dvě duplicity zůstávají UNKNOWN a 2482 validních nenamapovaných RR referencí je použitelných.
- Neexistuje cenový command/UI/endpoint a workflow ani ceny existujících objednávek se nemění.
