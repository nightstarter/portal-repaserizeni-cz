# HPSERVER Pohoda bridge connector

Samostatná konzolová aplikace pro Windows a PHP CLI 7.4.33. Nemá Laravel runtime ani Composer závislosti. Čte výhradně skladové řádky Pohody přes read-only SQL účet a iniciuje pouze odchozí HTTPS na portál. Neotevírá žádný příchozí port.

## Instalace

1. Zkopírujte celý tento adresář mimo webroot do `C:\Services\portal-repase-bridge`.
2. Zkopírujte `config\config.example.php` jako `config\config.local.php` a vyplňte jej přímo na HPSERVERU. Soubor je ignorovaný Gitem.
3. Vygenerujte nový secret příkazem `php bin\bridge.php key:generate`. Výstup uložte pouze do lokální konfigurace a bezpečného secret manageru; neposílejte jej e-mailem ani do logu.
4. Vytvořte samostatný SQL login/user s `SELECT` pouze na `dbo.SKz` a právy nutnými pro `INFORMATION_SCHEMA.COLUMNS` a `fn_my_permissions`. Nepoužívejte webový `db.php` ani účet Pohody s právy zápisu.
5. Ponechte `product_scope=sql_prefix`, `product_reference_prefixes=array('RR')` a `quantity_mode=unconfirmed`; nejprve spusťte diagnostiku a skladové dry-runy.

Minimální NTFS oprávnění servisního účtu:

- Read & Execute na aplikaci, `bin`, `src` a `config`;
- Read na `config.local.php` pouze pro servisní účet a administrátory;
- Modify pouze na `var`, `var\spool`, log, state a lock;
- žádná oprávnění pro IIS/IUSR a běžné interaktivní účty;
- zakažte dědění širokých práv z nadřazeného adresáře, pokud by zpřístupnilo konfiguraci.

## Konfigurace a množství

Secret je Base64 nejméně 32 náhodných bajtů. URL musí začínat `https://`; TLS peer a hostname verification nelze konfigurací vypnout. Výchozí sklad je `RefSklad=1`, `RelSkTyp=1`, kód `DECIN`. Produkční rozsah karet je výhradně SQL prefix `RR`:

```php
'product_scope' => 'sql_prefix',
'product_reference_prefixes' => array('RR'),
```

Dotaz používá bind parametr `:prefix` s hodnotou `RR%`; `%` přidává konektor. Prázdný prefix, `%`, `_`, hranaté wildcard rozsahy, escape a jiné znaky mimo úzkou bezpečnou abecedu konfigurace odmítne před SQL dotazem. Full-warehouse `COUNT` existuje pouze uvnitř ručního `diagnose`; inspect, dry-run, anomalies i sync vždy čtou stejný RR rozsah. Portálový produktový registr není podmínkou snapshotu.

`quantity_mode` je záměrně `unconfirmed`:

- `physical`: `available_quantity = StavZ`;
- `free`: `available_quantity = max(StavZ - Rezer, 0)`;
- `unconfirmed`: ostrý sync skončí bezpečným kódem `QUANTITY_MODE_UNCONFIRMED` a nic neodešle.

Rozhodnutí physical/free musí být schváleno vlastníkem procesu; konektor jej neodhaduje. `product_reference` se pouze trimuje, nemění case ani obsah.

## Příkazy

```text
php bin\bridge.php diagnose
php bin\bridge.php key:generate
php bin\bridge.php stock:inspect
php bin\bridge.php stock:dry-run
php bin\bridge.php stock:anomalies
php bin\bridge.php heartbeat:dry-run
php bin\bridge.php sync:once --dry-run
php bin\bridge.php sync:once
php bin\bridge.php commands:poll --dry-run
php bin\bridge.php commands:poll
```

`diagnose` provede pouze read-only SQL dotazy a HTTPS HEAD. Nezapisuje do Pohody ani portálu. `stock:inspect` zobrazí omezený RR vzorek IDS/StavZ/Rezer a obě varianty množství; výstup chraňte jako provozní data. `stock:dry-run` uvádí prefix, počet RR karet, unikátní a duplicitní IDS, necelá/záporná množství, rezervace a rozdíl physical/free. `stock:anomalies` přidá konkrétní provozní IDS s anomálií. `commands:poll --dry-run` záměrně neclaimuje lease a nepoužívá síť, proto hlásí nulový lokální náhled.

Pro ostrý sync musí být současně potvrzen `quantity_mode`, nastaveno `dry_run=false`, platný HMAC klíč a portálový feature flag. První nebo změněný dataset odešle plný chunkovaný snapshot. Nezměněný dataset odešle pouze malé `confirm-unchanged`; dry-run hlásí `FULL_SNAPSHOT_REQUIRED` nebo `UNCHANGED_CONFIRMATION_ONLY`. Fingerprint nezávisí na pořadí SQL řádků, ID, `DatSave` ani cenách. Retry používá stejný raw payload a doménové ID, ale vždy nový timestamp a nonce.

## Spool, retry a logy

Každý chunk má samostatný immutable `.payload` a `.meta.json`. Konektor posílá nejstarší pending chunk první. Timeout, HTTP 408/429/5xx používají exponenciální backoff s ±20% jitterem; `Retry-After` má přednost. Ostatní 4xx položku zablokují pro ruční zásah a automaticky ji neopakují. Při překročení velikosti nebo stáří spoolu se tvorba dalších snapshotů zastaví a nic neodeslaného se nemaže.

Spool metadata obsahují fingerprint SQL scope. Payload vytvořený pro jiný nebo starý neidentifikovaný rozsah se bezpečně zablokuje jako `SPOOL_SCOPE_MISMATCH`; konektor jej nesmaže ani neodešle.

State soubor obsahuje pouze poslední úspěšný `snapshot_run_id`, dataset fingerprint a čas potvrzení portálem. Při jeho ztrátě, konfliktu nebo `DATASET_FINGERPRINT_MISMATCH` vznikne nový plný snapshot. Confirmation je spoolováno a retryováno stejně bezpečně jako full snapshot.

Konektor je trvale read-only. `PRODUCT_REPRICING = DEFERRED_EXTERNAL_SYSTEM`: nezapisuje `SKz.NakupC`, `SKz.NakupDPH`, `SKz.CMKodNC` ani ceny PrestaShopu a produkční command whitelist je prázdný.

Lokální `flock` zabrání dvěma instancím `sync:once`. Log rotuje podle velikosti a retence a obsahuje jen run/batch ID, časy, počty, HTTP status a bezpečné kódy. Neukládá secret, DSN, SQL heslo, request/response body ani stack trace.

## Task Scheduler — pouze návrh

```text
Program:   C:\wamp64\bin\php\php7.4.33\php.exe
Arguments: -d xdebug.mode=off C:\Services\portal-repase-bridge\bin\bridge.php sync:once
Start in:  C:\Services\portal-repase-bridge
```

Nastavte opakování každých 5 minut, „Do not start a new instance“, neinteraktivní servisní účet bez logon práva, záznam návratového kódu a minimální NTFS/SQL oprávnění. Samostatnou denní diagnostiku není nutné plánovat. Fáze 7B úlohu nevytváří.

## Rotace a rollback

Při rotaci nejprve nastavte na portálu nový current a starý previous klíč, poté změňte konektor, ověřte heartbeat a až následně previous odstraňte. Rollback konektoru znamená zastavení úlohy a návrat adresáře/verze; spool a `config.local.php` zachovejte. Neodstraňujte pending payloady naslepo.

PHP 7.4 je podporovaný provozní kompromis fáze 7B, ale je EOL technický dluh. Ve fázi 8 naplánujte upgrade samostatného PHP CLI alespoň na 8.2, znovu spusťte diagnostiku a parity testy a teprve poté přepněte cestu v Task Scheduleru.
