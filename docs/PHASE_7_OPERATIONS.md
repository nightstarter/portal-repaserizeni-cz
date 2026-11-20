# Pohoda bridge – provozní runbook fáze 7

## Umístění a plánování

Konektor je mimo webroot v `C:\Services\portal-repase-bridge`. Lokální `config\config.local.php`, `var\state.json`, spool a log nesmí být verzované ani čitelné běžnými uživateli. Servisní účet má mít pouze read/execute na aplikaci, modify na `var`, přístup k privátnímu configu a SQL `SELECT` na potřebné objekty.

Task Scheduler:

```text
Program:   C:\wamp64\bin\php\php7.4.33\php.exe
Arguments: -d xdebug.mode=off C:\Services\portal-repase-bridge\bin\bridge.php sync:once
Start in:  C:\Services\portal-repase-bridge
Repeat:    every 5 minutes
Policy:    do not start a new instance if one is running
Account:   non-interactive service account
```

Windows Time musí běžet. Úspěšný běh vrací `0`; skutečná chyba vrací nenulový kód. V Task Scheduleru kontrolujte Last Run Result `0x0`, čas posledního běhu a že se instance nepřekrývají. Lokální process lock je druhá ochranná vrstva.

## Běžná kontrola

Na `/admin/integrations/pohoda` pod AK_ADMIN ověřte:

- poslední heartbeat a verzi konektoru;
- poslední confirmation, stáří ověření a zda není `STALE`;
- použitelný base run a jeho `COMPLETE/PARTIAL` stav;
- mapped/unmapped/rejected a agregované rejection codes;
- `receiving=0`, `failed=0` a prázdný command whitelist;
- rozumný poměr plných přenosů a confirmations za 24 hodin.

Aktuální známý base je PARTIAL: 2482 `ACCEPTED + UNMAPPED`, dvě `DUPLICATE_PRODUCT_REFERENCE`. Konkrétní dvě IDS nejsou uloženy v dokumentaci/repozitáři; zjistěte je jen z již existujícího lokálního `stock:anomalies` výstupu a zacházejte s nimi jako s provozním mapovacím úkolem. Nemažte base run ani jeho odmítnuté položky.

## Spool a log

Spool je v `C:\Services\portal-repase-bridge\var\spool`, log typicky `var\bridge.log`. Kontrolujte počet a stáří `.payload`/`.meta.json`, volné místo a bezpečné kódy. Neupravujte payload ani batch ID a nemažte pending/blocked spool naslepo. Po úspěšném ACK se konkrétní spool položka odstraní; při 429/5xx/timeoutu zůstává pro retry se stejnými raw bytes a novým nonce.

Log rotuje podle `log_max_bytes` a `log_retention_days`. Nesmí obsahovat secret, DSN, SQL heslo, raw payload/response nebo stack trace s konfigurací.

## Incidentní postupy

### STALE

1. Zkontrolujte Last Run Result, lokální lock, spool, log a Windows Time.
2. Ověřte SQL čtení příkazem `diagnose` a poté `stock:dry-run`; nic neposílejte, dokud není příčina jasná.
3. Ověřte HTTPS/DNS a stav portálu. Zachovejte pending spool.
4. Po nápravě spusťte jeden kontrolovaný `sync:once`; ověřte heartbeat/freshness a že nedošlo k falešnému COMPLETE.

### HMAC chyba

Zkontrolujte UTC čas, key ID a Base64 secret bez výpisu hodnoty do logu/ticketu. Ověřte canonical protokol a zda config cache portálu odpovídá. Nepovolujte HTTP ani TLS verification off. Při podezření na kompromitaci proveďte rotaci.

### Rotace klíče

1. Vygenerujte nový 32+ byte secret lokálně.
2. Na portálu nastavte nový current a starý current jako previous, rebuildněte config cache.
3. Změňte key ID/secret konektoru bezpečným kanálem a ověřte heartbeat.
4. Po stabilním provozu odstraňte previous. Secret nikdy neukládejte do DB, Gitu nebo ticketu.

### Změna SQL hesla

Dočasně zakažte Task Scheduler, změňte heslo dedikovaného read-only účtu a lokální `config.local.php`, spusťte `diagnose`, znovu potvrďte absenci INSERT/UPDATE/DELETE/ALTER a scheduler obnovte. DSN/heslo nevypisujte.

### SQL nebo HTTPS výpadek

Scheduler lze ponechat běžet, pokud spool není u limitu; retry používá backoff. Při hrozícím zaplnění scheduler bezpečně vypněte, ale spool uchovejte. Po obnově ověřte nejstarší pending payload, freshness a heartbeat. Nevytvářejte inbound firewall výjimku.

### Bezpečné vypnutí bridge

Vypněte Task Scheduler a nastavte portálový `POHODA_BRIDGE_ENABLED=false` kontrolovanou změnou konfigurace/cache. Zachovejte config, state, spool, nonce/audit, snapshot runs/items a confirmations. Zakázané API nic nezapisuje. Při opětovném zapnutí nejprve `diagnose` a dry-run.

## Trvalé zákazy a dluh

- Ručně nemažte auditní historii, partial runy, confirmations ani neodeslaný spool.
- Konektor je read-only; command whitelist je produkčně prázdný a přecenění je `DEFERRED_EXTERNAL_SYSTEM`.
- PHP CLI 7.4.33 je EOL. Upgrade na samostatné PHP CLI 8.2+ včetně extensions, diagnose, HMAC parity a bezpečného přepnutí Scheduleru je úkol fáze 8.
