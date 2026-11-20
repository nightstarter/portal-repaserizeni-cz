# Fáze 7C — potvrzování nezměněného skladu

## Rozsah

Konektor počítá deterministický SHA-256 fingerprint z warehouse, přesných trimovaných referencí, dostupného a rezervovaného množství a agregovaného manifestu zdrojových anomálií. Řádky se před hashováním kanonicky seřadí. ID řádku, `DatSave`, ceny a ostatní neskladová pole nejsou součástí fingerprintu.

První nebo změněný dataset používá stávající chunkovaný `POST /api/internal/v1/stock-snapshots`. Nezměněný dataset používá malý HMAC request `POST /api/internal/v1/stock-snapshots/confirm-unchanged`. Lokální state ukládá poslední portálem přijatý run, fingerprint a čas potvrzení. Ztráta stavu, mismatch nebo neexistující base run vedou k novému plnému snapshotu.

Migrace `2026_11_20_000016_add_stock_snapshot_confirmations.php` přidává nullable fingerprint k runu a append-only confirmations. Staré runy zůstávají beze změny a bez fingerprintu je nelze potvrzovat.

## Freshness

Resolver používá nejnovější COMPLETE/PARTIAL base run. Čerstvost určuje poslední `received_at` platného confirmation, jinak čas dokončení base runu. Množství se z confirmation nikdy nečte. PARTIAL zůstává PARTIAL a chybějící nebo duplicitní reference zůstává `UNKNOWN`.

## Přecenění

`PRODUCT_REPRICING = DEFERRED_EXTERNAL_SYSTEM`. Produkční command whitelist je prázdný. Neexistuje cenový command, UI ani zápis do Pohody/PrestaShopu. Konektor zůstává read-only a ceny existujících objednávek se zpětně nemění.

## Provozní omezení

V této fázi nebyla spuštěna produkční migrace, změněna produkční konfigurace ani Task Scheduler a nebyl odeslán živý snapshot nebo confirmation. PHP 7.4 zůstává dočasný technický dluh konektoru.
