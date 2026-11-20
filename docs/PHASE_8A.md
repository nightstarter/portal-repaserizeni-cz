# Fáze 8A – produkční baseline, oprávnění a preflight

Datum implementace: 20. 11. 2026. Rozsah je výhradně 8A; zálohy/DR (8B), monitoring/alerty (8C), izolované smoke/load/demo (8D) a go-live/importní start (8E) nebyly zahájeny.

## Dodané části

- bezpečnostní baseline v `SECURITY_BASELINE.md`;
- centrální rolová matice `config/permission_matrix.php` a dokument `PERMISSION_MATRIX.md`;
- read-only služba a CLI `ops:preflight` s text/JSON, `OK|WARN|ERROR` a bezpečnými exit codes;
- AK_ADMIN-only read-only přehled `/admin/operations/preflight` bez PII, secrets a opravných akcí;
- regrese preflight klasifikace, stale/known PARTIAL snapshotu, sanitizace, disk/private storage/whitelistu a konstantního query countu;
- regrese přímých URL/POST, admin endpointů a absolutního zákazu doménového zápisu READ_ONLY.

## Bezpečnostní nálezy opravené v 8A

1. READ_ONLY mohl otevřením diskuse zapsat unread stav a mohl přímo POSTnout mark-read. GET nyní pro READ_ONLY nic nezapisuje a mark-read POST vrací `403`.
2. Některé přímé URL/POST cizí zakázky používaly výchozí policy `403`, která potvrzovala existenci objektu. Parent order visibility nyní fail-closed vrací `404`; následná action permission viditelného objektu zůstává samostatná.

## Neměnné produkční hranice

- Produkční DB, `.env`, cron, Task Scheduler, Pohoda, HPSERVER a private storage nebyly změněny.
- Nebyl spuštěn migrate/seed, ostrý import, bridge snapshot/command ani SMTP přenos.
- `LEGACY_SHOP_IMPORT_ENABLED=false`, `DISCUSSION_EMAIL_ENABLED=false`, `BUYBACK_CUSTOMER_EMAIL_ENABLED=false`, prázdný command whitelist a `PRODUCT_REPRICING=DEFERRED_EXTERNAL_SYSTEM` zůstávají bezpečný baseline.
- Portál nemá SQL připojení do Pohody. Connector je read-only a zůstává PHP CLI 7.4.33 kompatibilní.
- PHP 7.4.33 a Windows Server 2012 R2 jsou dokumentovaný WARN/přijaté riziko; upgrade není scope ani blokátor 8A.

## Výsledek automatizované akceptace

- Laravel suite: **432 testů / 2201 assertions**, pouze `APP_ENV=testing`, SQLite `:memory:`; fail-fast guard izolaci potvrdil.
- Standalone PHP 7.4 connector suite: **26 testů / 199 assertions**.
- `composer validate --strict`, Pint, PHP syntax kontrola a kontrola Markdown odkazů prošly.
- `composer audit --locked`: žádné security vulnerability advisory; tedy žádná přímá produkční, dev-only ani nepoužitelná advisory a žádný dependency blokátor.
- Secret/history scan nenalezl skutečný privátní klíč ani vyplněné DB/SMTP/bridge tajemství v pracovním stromu nebo historii aktuální větve.

Read-only produkční `migrate:status` potvrdil všech 20 migrací 000001–000016 jako `Ran`, bez pending migrace. Žádná migrace nebyla spuštěna.

Read-only produkční `ops:preflight --json` ověřil jako `OK` prostředí/debug, DB, migrace, runtime adresáře, private storage, cache, čerstvý heartbeat, známý PARTIAL 2482+2, nulové receiving/failed runy, nulový pending/failed outbox, bezpečné feature flagy, prázdný whitelist, allowlisty DECIN/RR, vypnuté SMTP funkce a disk. `WARN` jsou ruční Task Scheduler kontrola a přijatý runtime/OS dluh.

## Neuzavřený blokátor

Kontrola `https_and_session` v načtené produkční konfiguraci vrátila `ERROR`: session flags jsou bezpečné, ale scheme `APP_URL` není HTTPS. Podle zadání nebyl `.env` ani config cache změněn. Provozovatel musí bezpečným deployment postupem nastavit/ověřit produkční `APP_URL=https://portal.repaserizeni.cz`, rebuildnout config cache a zopakovat `ops:preflight --json`. Teprve výsledek bez `ERROR` dovolí označit 8A za dokončenou.

Task Scheduler GUI, živý TLS certifikát/redirect/vhost/ACL, SQL grants konektoru a produkční browser přehled zůstávají výslovně ruční kontroly.
