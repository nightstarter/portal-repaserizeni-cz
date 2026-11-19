# Fáze 7A — bezpečný portálový základ Pohoda bridge API

## Rozsah

Fáze zavádí pouze přijímací portálovou stranu. Portál nemá a nezískal SQL connection k Pohodě. Budoucí konektor na HPSERVERU bude iniciovat odchozí HTTPS; žádná inbound služba na HPSERVERU ani konektor nebyly vytvořeny.

Bridge je ve výchozím stavu vypnutý (`POHODA_BRIDGE_ENABLED=false`). Produkční `.env`, databáze, cron, private storage, `public/.htaccess` a `public/.user.ini` nebyly fází měněny.

## Implementace

- Verzované stateless JSON API pod `/api/internal/v1` používá HMAC-SHA256, jednorázové nonce, maximálně ±300 s timestamp, raw-body hash, rate limit a volitelný IP allowlist.
- Current/previous key konfigurace umožňuje překryvnou rotaci. Secret je pouze Base64 v environmentu, nejméně 32 dekódovaných bajtů, nikdy v DB.
- Snapshot batch a položky jsou append-only. `batch_id` je idempotentní; jednotlivé chyby jsou izolované a neznámé reference zůstanou v bezpečné mapovací historii.
- Resolver fyzické dostupnosti používá nejnovější přijatou položku podle zdrojového času. Chybějící a zastaralá data nejsou potvrzením skladu a ponechají workflow v `PENDING_STOCK_CHECK`.
- Heartbeat má striktní allowlist polí a idempotentní `event_id`.
- Command outbox má whitelist typů, atomický řádkový claim, unikátní lease token, expiraci/reclaim a idempotentní result. Produkční whitelist je v 7A prázdný; testovací `NOOP` se aktivuje pouze v testu. Obecný SQL payload je odmítnut.
- Read-only diagnostika `/admin/integrations/pohoda` je dostupná jen AUTOKLUBY uživateli s rolí AK_ADMIN. Ostatním vrací 404. Nezobrazuje secret, podpis, nonce, hostname ani SQL údaje.
- Audit obsahuje bezpečné identifikátory, stav a agregované počty, nikoli raw request, HMAC materiál, zákaznická data nebo interní výjimky. U odmítnuté autentizace se nepersistují útočníkem řízené hlavičky.

Úplný wire kontrakt je v [POHODA_BRIDGE_PROTOCOL.md](POHODA_BRIDGE_PROTOCOL.md).

## Databáze

Rozšiřující migrace `2026_11_19_000014_create_phase_7a_pohoda_bridge.php` přidává:

- `bridge_nonces`;
- `stock_snapshot_batches`;
- `stock_snapshot_items`;
- `bridge_heartbeats`;
- `bridge_commands`;
- `bridge_command_results`.

Migrace nemění ani nemaže existující data. Typy, indexy, explicitní názvy složených indexů, cizí klíče a JSON jsou kompatibilní s MariaDB 10.11. Snapshot item odkazuje nullable na existující `legacy_product_configurations`; při odstranění mapování se reference nastaví na NULL, historie zdrojové položky zůstává. Výsledky příkazů používají restriktivní FK.

## Konfigurace

Příklad prostředí obsahuje:

```dotenv
POHODA_BRIDGE_ENABLED=false
POHODA_BRIDGE_KEY_ID=
POHODA_BRIDGE_SECRET=
POHODA_BRIDGE_PREVIOUS_KEY_ID=
POHODA_BRIDGE_PREVIOUS_SECRET=
POHODA_BRIDGE_TIMESTAMP_TOLERANCE=300
POHODA_BRIDGE_STALE_MINUTES=30
POHODA_BRIDGE_MAX_BATCH_ITEMS=500
POHODA_BRIDGE_MAX_BODY_BYTES=2097152
POHODA_BRIDGE_IP_ALLOWLIST=
POHODA_BRIDGE_RATE_LIMIT_PER_MINUTE=60
POHODA_BRIDGE_COMMAND_LEASE_MINUTES=5
```

Současně zůstávají vypnuté `LEGACY_SHOP_IMPORT_ENABLED`, `DISCUSSION_EMAIL_ENABLED` a `BUYBACK_CUSTOMER_EMAIL_ENABLED`.

## Ověření a akceptační hranice

Automatické testy běží přes existující fail-fast guard výhradně s `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Pokrývají podpis, replay, key rotation, čas, raw body, canonical query, disabled režim, rate limit, allowlist, snapshoty, stale stav, heartbeat, command claim/lease/result, diagnostiku, únik tajemství/PII a regresi workflow.

V 7A nebylo provedeno:

- vytvoření či spuštění HPSERVER konektoru;
- přístup portálu k SQL Pohody;
- produkční migrace nebo změna produkčního `.env`;
- přenos skutečných snapshotů;
- ostré cenové nebo jiné doménové command typy;
- provozní load test více fyzických worker procesů proti MariaDB.

Command doručení je lease-based at-least-once. Konektor musí být doménově idempotentní; portál nepředstírá exactly-once provedení externího příkazu.
