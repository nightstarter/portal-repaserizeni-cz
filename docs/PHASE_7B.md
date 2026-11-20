# Fáze 7B — HPSERVER Pohoda bridge konektor

## Výsledek

Repozitář obsahuje samostatnou aplikaci `connector/pohoda-bridge`, určenou ke kopírování do `C:\Services\portal-repase-bridge`. Je kompatibilní s PHP CLI 7.4, nepoužívá Laravel ani Composer balíčky, neotevírá listener a neobsahuje skutečnou konfiguraci nebo secret.

Konektor implementuje diagnostiku, read-only parametrizované čtení `SKz`, výslovnou volbu významu množství, deterministický JSON, HMAC parity s portálem, chunking, atomický souborový spool, oldest-first retry, jitter/backoff, lokální process lock, bezpečný heartbeat, lease command polling a sanitizované rotující logování.

Upřesnění 7B.1 omezuje produkční zdroj referencí na `SKz.IDS LIKE :prefix`, kde je `:prefix=RR%`, při `RefSklad=1` a `RelSkTyp=1`. Prefix je parametr, nikoli část SQL textu. Inspect, dry-run, anomalies a sync používají shodný rozsah; portálový registr produktů se pro vymezení snapshotu nepoužívá. Konfigurace wildcardy a full-warehouse scope odmítá.

Korekce 7B.2 odstraňuje chybnou závislost snapshotu na předchozím importu objednávek. Validní RR položka je přijatá i bez `LegacyProductConfiguration`, zachová množství, row version a fingerprint a vrací `mapping_status=UNMAPPED`. Pozdější mapování historický append-only snapshot nemění. Portál navíc nezávisle vynucuje `POHODA_BRIDGE_PRODUCT_PREFIXES=RR` a `POHODA_BRIDGE_WAREHOUSES=DECIN`; diagnostika agreguje mapped, unmapped a skutečně odmítnuté položky bez výpisu tisíců referencí.

## Portálová změna

Protože limit jednoho requestu je 500 položek, migrace `2026_11_20_000015_add_chunked_stock_snapshot_runs.php` přidává `stock_snapshot_runs`, vazbu chunků na run a bezpečné heartbeat error codes. Starý jednodávkový 7A payload zůstává podporovaný jako run o jednom chunku.

Run je `RECEIVING`, dokud nejsou přijaté všechny indexy a finální marker. Teprve potom se atomicky stane `COMPLETE` nebo `PARTIAL`. Resolver používá pouze dokončený nejnovější run. `RECEIVING` a `FAILED` nikdy nevytvoří zdání aktuálního skladu; timeoutované runy označí následující validní snapshot/heartbeat jako `FAILED`.

## Bezpečnostní hranice

- Portál stále nemá SQL connection k Pohodě.
- SQL dotaz konektoru je pouze parametrizovaný `SELECT` nad `SKz`, bez `NOLOCK` a pod `REPEATABLE READ` transakcí.
- Ostrý snapshot zahrnuje pouze doslovný prefix `RR`; starý spool bez odpovídajícího scope fingerprintu se neodešle.
- Výchozí `quantity_mode=unconfirmed` blokuje ostré odeslání.
- Produkční command whitelist na portálu zůstává prázdný; konektor dovolí `NOOP` pouze při `environment=testing` a odmítá SQL payload.
- TLS peer/hostname verification je natvrdo zapnuté.
- Dry-run neodesílá HTTP a nikdy nezapisuje do SQL.
- Secret, DSN, SQL heslo, interní cesty a stack trace se neposílají ani nelogují.

## Testy

Standalone connector test runner ověřuje HMAC vektor, raw body, nonce, quantity režimy, chunking/finalizaci, stabilní retry, spool limit, lock, TLS, backoff/429/5xx, parametrizovaný RR prefix, odmítnutí wildcardů/injection, anomálie, command allowlist, heartbeat, sanitizaci a PHP 7.4 kontrakt. Composer `check` jej spouští před Laravel test suite. Portálové testy nadále používají pouze SQLite `:memory:`.

## Neprovedené provozní kroky

- Konektor nebyl zkopírován ani spuštěn na HPSERVERU.
- Nebylo navázáno spojení k produkční Pohodě ani produkčnímu bridge API.
- Nebyla vytvořena Task Scheduler úloha.
- Nebyl vytvořen skutečný secret ani `config.local.php`.
- Migrace 000014/000015 nebyly aplikovány do produkce a bridge zůstává vypnutý.

Známým omezením 7B je produkční command whitelist prázdný a neexistují cenové příkazy; ty patří do 7C. PHP 7.4 je EOL a musí být ve fázi 8 nahrazeno PHP CLI 8.2+.
