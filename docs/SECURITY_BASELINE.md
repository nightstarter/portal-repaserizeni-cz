# Produkční bezpečnostní baseline – Fáze 8A

Baseline je statická a automatizovaná revize kódu a bezpečné konfigurace. Neprovádí penetrační test produkce, nemění `.env`, databázi, cron, Task Scheduler, Pohodu, HPSERVER ani private storage.

## Automaticky ověřené kontroly

- Produkční preflight vyžaduje `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, `Secure`, `HttpOnly` a `SameSite=Lax|Strict` session cookie. Login je rate-limitovaný a po přihlášení regeneruje session.
- Všechny zapisovací webové routy jsou v Laravel `web` middleware skupině a mají CSRF ochranu. Interní bridge API session/CSRF nepoužívá: vyžaduje HTTPS, feature flag, rate limit a HMAC.
- HMAC-SHA256 pokrývá metodu, path, kanonickou query, timestamp, jednorázový nonce a SHA-256 přesných raw-body bytes. Timestamp tolerance je maximálně 300 sekund, porovnání používá `hash_equals` a nonce má unikátní DB omezení.
- Cizí zakázka, thread, item, shipment, QR, dokument a příloha jsou row-level autorizované. Kde by `403` prozrazovalo existenci cizího objektu, vrací endpoint bezpečnou `404`.
- Uploady kontrolují bezpečnou jedinou příponu, deklarované i skutečné MIME, velikost a SHA-256 checksum. PHP, HTML, SVG, JavaScript, dvojité přípony a nesoulad MIME jsou odmítnuty.
- Dokumenty a přílohy jsou na privátním disku mimo `public/`; stažení prochází policy/controllerem a storage cesta se nevystavuje.
- SMTP a integrační výjimky se ukládají jako obecné bezpečné kódy/zprávy. Preflight nevypisuje secret, heslo, DSN, podpis, nonce, raw payload, `.env` ani PII.
- Statická kontrola pracovního stromu a historie aktuální větve nenalezla skutečný privátní klíč ani vyplněné DB/SMTP/bridge tajemství. `.env`, logy, dumpy, connector local config/state/spool a private data nejsou součástí změn 8A.
- Portál nemá SQL Server/Pohoda DSN ani přímé SQL připojení. Samostatný PHP 7.4 kompatibilní konektor používá parametrizovaný `SELECT`; jeho zdrojový SQL neobsahuje `INSERT`, `UPDATE`, `DELETE`, `ALTER` ani `EXEC`.
- Produkční bridge command whitelist je prázdný. Neexistuje cenový command ani endpoint; snapshot smí měnit jen append-only skladová data a nikdy workflow či cenu objednávky.
- `PRODUCT_REPRICING=DEFERRED_EXTERNAL_SYSTEM`; legacy import a oba typy produkčních e-mailů zůstávají vypnuté.
- Produkční log channel používá denní rotaci. Aplikační audit filtruje citlivé klíče a bridge přijímá/loguje pouze allowlist bezpečných kódů.
- Monitoring ukládá pouze stabilní check key, severity, fingerprint, bezpečné summary a allowlist sanitizovaných metadat. Private-storage kontrola vrací jen agregované počty. Veřejný `/health` nevrací verze, DB identity ani detail checks.
- Monitoring je chráněn globálním cache lockem, alert aktivním unikátním dedup klíčem a ACK optimistickou `row_version`. Alert events jsou v aplikační vrstvě append-only.

## Ruční provozní potvrzení

- skutečný TLS certifikát, redirect HTTP → HTTPS a security headers na veřejném vhostu;
- document root přesně na `public/`, zakázaný directory listing a nepřístupnost `.env`, `vendor/`, `storage/` a zdrojů přes HTTP;
- oprávnění OS uživatele k runtime/private adresářům a retence produkčních logů;
- skutečná oprávnění SQL účtu konektoru (`SELECT` only), absence inbound listeneru/firewall pravidla a stav Windows Time;
- Task Scheduler Last Run Result, interval, nepřekrývání instancí a lokální ACL connector config/state/spool/log;
- vizuální kontrola admin stránky bez zákaznických dat na produkčních datech.
- ruční kontrola 8C admin UI, rate limitu `/health`, scheduler intervalů a vybraného skutečného alert kanálu po budoucí aktivaci.

## Dependency audit

`composer validate --strict` prošel. `composer audit --locked` nenalezl žádné security vulnerability advisory; není tedy co třídit jako přímou produkční zranitelnost, dev-only nález, nepoužitelné advisory ani produkční blokátor. Major upgrade nebyl proveden.

## Přijaté riziko

PHP CLI 7.4.33 a Windows Server 2012 R2 na HPSERVERU jsou EOL technický dluh. Fáze 8A je eviduje jako `WARN`, nikoli `ERROR`; upgrade, migrace WAMPu, intranetu, SQL Serveru nebo Pohody nejsou součástí 8A. Konektor musí zůstat kompatibilní s PHP CLI 7.4.33.
