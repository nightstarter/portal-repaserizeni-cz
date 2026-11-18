# Fáze 4 — deployment audit a plán

Datum read-only auditu: 2026-11-18. Tento dokument je plán, nikoli autorizace deploymentu. Produkční migrace ani DB data nebyly změněny. Přechodný testovací report ve private storage, jeho odstranění a oprava izolace jsou popsány v `PHASE_4_ACCEPTANCE.md`; žádný dokument Fáze 4 nebyl vytvořen.

## Produkční stav

- aplikace: `APP_ENV=production`;
- DB: `10.11.14-MariaDB-0ubuntu0.24.04.1-log`;
- Apache DocumentRoot: `/var/www/portal.repaserizeni.cz/web/public`;
- private disk: `/var/www/portal.repaserizeni.cz/web/storage/app/private`, mimo DocumentRoot, `serve=false`;
- virtual host používá PHP 8.5 FPM socket; pool běží jako `www-data:www-data`;
- adresář je `akushop:www-data`, mode `775`; PHP má skupinový zápis;
- aplikační limit 10240 KB versus PHP `2M/8M` — **deployment blocker**.

## Pending migrace

Read-only `php8.4 artisan migrate:status` ukázal přesně:

```text
2026_11_18_000007_create_phase_4a_core_returns  Pending
2026_11_18_000008_create_phase_4b_documents     Pending
```

Produkce zatím nemá `core_return_inspections`, `finance_entries`, `documents` ani `document_events`. Po deploymentu musí být všechny čtyři nové tabulky prázdné; ostrý import ani scheduler je nemají plnit.

### 000007 — CORE_RETURN kontroly a ledger

Pouze rozšiřující migrace vytváří:

- `core_return_inspections`: bigint PK; FK order/thread/item/actor user/actor organization; piece/transport kódy; čtyři boolean flags; nullable note; `inspected_at`; index `(process_thread_id,inspected_at)`.
- `finance_entries`: bigint PK; FK order/thread, nullable thread event, audit, actor user/organization a nullable self-FK `corrects_entry_id`; type, `decimal(18,2)`, ISO currency, status, reason, unikátní idempotency key, request hash, `recorded_at`; indexy type, timestamp a `(process_thread_id,type)`.

FK používají `RESTRICT ON DELETE`. Bigint, varchar/char, text, boolean, timestamp a decimal jsou kompatibilní s MariaDB 10.11.14. `CREATE TABLE` vyžaduje krátké metadata locky na referencované tabulky při vytvoření FK, ale nepřepisuje existující řádky; riziko dlouhého locku je nízké. Přesto použít servisní okno a ukončit dlouhé transakce.

`down()` tabulky zahodí a zničí kontroly i finanční historii. Běžný rollback proto vrací kód, ne migraci; schema rollback je jen součást úplné obnovy.

### 000008 — dokumenty

Pouze rozšiřující migrace vytváří:

- `documents`: bigint PK; unikátní UUID token; FK order, nullable thread, uploader/organizace, nullable nahrazený dokument a invalidátor; type, disk, unikátní storage name, očištěný název, MIME, extension, size, SHA-256, číslo/datum, `decimal(18,2)`, ISO currency, status, unikátní nullable `active_key`, invalidace a timestamps. Indexy type, status, `(order_id,type,status)`, `(process_thread_id,type,status)`.
- `document_events`: bigint PK; FK document/order/actor/organization; action, unikátní idempotency key, request hash, reason, JSON metadata, `performed_at`.

MariaDB 10.11 podporuje použité JSON, string UUID, decimal, indexy i self-FK. Unikátní nullable `active_key` dovolí více NULL, ale jedinou konkrétní aktivní hodnotu. Lock riziko odpovídá novým tabulkám a krátkým metadata lockům FK. `down()` maže historii metadat, zatímco soubory by zůstaly na disku; nepoužívat jako běžný rollback.

### Pořadí nasazení

1. DB a filesystem záloha do stejného konzistentního bodu.
2. Storage a PHP limit setup níže.
3. Nasadit auditované commity.
4. V servisním okně po samostatném schválení spustit 000007 a potom 000008 standardním `php8.4 artisan migrate --force`.
5. Read-only ověřit `migrate:status`, FK/indexy a nulové počty čtyř nových tabulek.
6. Provést browser smoke test na předem schválených datech; nevytvářet anonymní produkční demo.

Ve 4C nebyl krok 4 ani jiný mutační krok proveden.

## Private storage setup

Pokud adresář neexistuje, provozní správce jej vytvoří přesně:

```bash
sudo install -d -o akushop -g www-data -m 2770 /var/www/portal.repaserizeni.cz/web/storage/app/private
sudo chown akushop:www-data /var/www/portal.repaserizeni.cz/web/storage/app/private
sudo chmod 2770 /var/www/portal.repaserizeni.cz/web/storage/app/private
```

Mode `2770` dává zápis deploy uživateli a PHP skupině, zachovává skupinu na podsložkách a nepovoluje ostatní. Rodiče musí být průchozí pro `www-data`. Web server musí obsluhovat pouze `web/public`; nevytvářet symlink z `public/storage` do private adresáře. Aktuální adresář existuje jako `775`; 4C jej neměnila. Zpřísnění je samostatný krok po ověření zálohovacího agenta.

## PHP upload limity

V `/etc/php/8.5/fpm/php.ini` nastavit:

```ini
upload_max_filesize = 10M
post_max_size = 12M
```

`post_max_size` potřebuje rezervu pro multipart metadata. Poté kontrolovaně reloadovat PHP 8.5 FPM a ověřit aktivní FPM konfiguraci, nikoli jen CLI. Dokud produkční request nepotvrdí nejméně 10M/12M, upload 10MB dokumentů není přijat.

## Záloha, obnova a retence

DB metadata a private soubory jsou jedna logická jednotka. Konzistentní záloha vyžaduje pozastavit dokumentové zápisy, dokončit requesty, vytvořit DB snapshot a filesystem snapshot ve stejném označeném bodu a až potom zápisy obnovit. Záloha obsahuje DB, `storage/app/private` a manifest s časem/verzí commitu.

Obnova probíhá izolovaně: obnovit DB i soubory ze stejného bodu, ověřit aktivní/invalidované počty, existenci každého `storage_name`, SHA-256, oprávnění a až potom přepnout aplikaci. Nekombinovat novější DB se staršími soubory ani opačně.

Invalidované faktury a dobropisy se automaticky nemažou. Výchozí retence je neomezená do schválení právní/účetní politiky. Antivirová kontrola není implementovaná; před širším externím uploadem zvážit scanner/quarantine.

## Rollback

- Při aplikační chybě zastavit mutace a vrátit kód; aditivní tabulky a private soubory ponechat.
- Nespouštět automaticky `migrate:rollback/reset/refresh/fresh` ani `db:wipe`.
- Schema rollback připadá v úvahu pouze jako schválená úplná obnova konzistentní DB+filesystem zálohy. Preferovat dopřednou opravnou migraci.
- Po prvním dokumentu je `down()` destruktivní; návrat musí zachovat tabulky i soubory.
- Při nekonzistenci izolovat uploady, zastavit potvrzení plateb a obnovit oba zdroje do stejného checkpointu.

## Ruční browser smoke test

Provést v CZ i PL, na desktopu a v mobilním viewportu 390 × 844 px:

1. AUTOKLUBY: otevřít frontu Vratky, ověřit otevřené větve, lokaci a další akci.
2. Přijmout vratku a vyplnit kus, přepravní stav, čtyři flags a poznámku; ověřit audit a nezměněný produkt u jiného kusu.
3. Odeslat přes `AK_TO_PL`, přijmout jako Polsko a ověřit `CORE_INSPECTION` a lokaci POLAND.
4. Vyzkoušet plné, krácené s částkou/důvodem a explicitní bez-zálohové rozhodnutí.
5. Nahrát fakturu jako PDF/JPEG/PNG; odmítnout nepovolený/dvojitý typ a ověřit absenci veřejné URL.
6. Stáhnout aktivní dokument; ověřit attachment, bezpečný název a `nosniff`.
7. Opravit fakturu; ověřit invalidaci, jedinou aktivní verzi a reverzní ledger. Starou verzi běžně nestáhnout.
8. Nahrát dobropis; chybnou částku/měnu a bez-zálohovou větev odmítnout.
9. Potvrdit platbu; replay nesmí vytvořit druhou. Jiná aktivní větev drží order otevřený.
10. Přepnout CZ/PL; ověřit stavy, formuláře, chyby, doklady, ledger a KPI.
11. Na mobilu ověřit inputs, textarea, karty, tabulky a dosažitelná tlačítka bez overflow.
12. Jako cizí Polsko zkusit detail, přímý POST a známý/neexistující token; oba downloady musí skončit shodnou 404 bez změny dat.

Browser test se ve 4C neprovedl bez schválených bezpečných dat/účtů. Checklist zůstává povinnou deployment branou.
