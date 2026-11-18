# Fáze 5 — deployment plán

Tento dokument je plán, nikoli autorizace nasazení. Fáze 5D neprovedla push, pull, migraci, změnu `.env`, crontabu, produkční DB ani private storage.

## Předpoklady a stop podmínky

Deployment zastavit, pokud není splněno cokoli z následujícího:

- schválený commit/tag a zelený `composer check`;
- PHP 8.5 se všemi požadovanými moduly;
- MariaDB 10.11, dostatek místa a bez dlouhé transakce/metadata locku nad cílovými tabulkami;
- ověřená záloha produkční DB **a** `storage/app/private`, včetně testu obnovy;
- produkční `APP_ENV=production`, `APP_DEBUG=false`, `LEGACY_SHOP_IMPORT_ENABLED=false`, `POHODA_BRIDGE_ENABLED=false`;
- bezpečný SMTP testovací mailbox, který nepatří reálnému zákazníkovi;
- připravený monitoring scheduleru, worker commandu, failed eventů a aplikačního logu.

## Stav a pořadí migrací

Read-only audit před deploymentem ukázal jako pending:

1. `2026_11_18_000009_create_phase_5a_discussion`;
2. `2026_11_18_000010_extend_outbox_events_for_discussion_notifications`;
3. `2026_11_18_000011_add_phase_5_query_indexes`.

Pořadí se nesmí měnit. `000009` vytvoří nové tabulky, `000010` rozšíří outbox a `000011` doplní kompozitní indexy. Před DDL ověřit dlouhé transakce a metadata locks; index build může držet lock a zatížit I/O. Migrace nemažou ani nepřepisují existující aplikační data, ale jejich `down()` se po produkčním zápisu nesmí používat jako automatický rollback.

## Řízený deployment

1. Vyhlásit servisní okno a pozastavit webové zápisy i případný scheduler pro tento portál.
2. Vytvořit timestampovanou DB zálohu a konzistentní zálohu `/var/www/portal.repaserizeni.cz/web/storage/app/private`; ověřit čitelnost a postup obnovy.
3. Na důvěryhodném stroji pushnout přesně schválené commity/tag. Na serveru provést pouze fast-forward pull/fetch+checkout konkrétního hashe; nepoužívat force push, rebase ani merge neauditovaných změn.
4. Ověřit `git status`, hash commitu a že lokální provozní soubory nebyly přepsány.
5. Spustit:

   ```bash
   cd /var/www/portal.repaserizeni.cz/web
   php8.5 composer.phar install --no-dev --classmap-authoritative
   ```

6. V produkčním secretu nejprve nastavit notifikace bezpečně vypnuté:

   ```dotenv
   DISCUSSION_EMAIL_ENABLED=false
   DISCUSSION_EMAIL_MAX_ATTEMPTS=5
   DISCUSSION_EMAIL_BATCH_SIZE=50
   DISCUSSION_EMAIL_RETRY_MINUTES=5
   DISCUSSION_EMAIL_LOCK_MINUTES=10
   MAIL_MAILER=smtp
   MAIL_HOST=
   MAIL_PORT=587
   MAIL_USERNAME=
   MAIL_PASSWORD=
   MAIL_ENCRYPTION=tls
   MAIL_FROM_ADDRESS=
   MAIL_FROM_NAME="Portál Repase řízení"
   ```

   Heslo nevkládat do shell historie, logu, ticketu ani repozitáře.

7. Read-only ověřit `php8.5 artisan migrate:status`, DB verzi, zálohu a absenci dlouhých transakcí. Potom v servisním okně spustit přesně jednou:

   ```bash
   php8.5 artisan migrate --force
   ```

8. Znovu ověřit `migrate:status`, existenci tabulek/sloupců/FK/unique/indexů a nulový nebo očekávaný počet nových řádků.
9. Sestavit cache:

   ```bash
   php8.5 artisan config:cache
   php8.5 artisan route:cache
   php8.5 artisan view:cache
   ```

10. Ponechat `DISCUSSION_EMAIL_ENABLED=false`, otevřít web a provést CZ/PL/READ_ONLY část checklistu z `PHASE_5_ACCEPTANCE.md`. Odeslat jen schválenou testovací zprávu bez PII a ověřit, že vznikl pending outbox bez SMTP pokusu.
11. Přidat aplikační cron pod správným provozním uživatelem:

   ```cron
   * * * * * cd /var/www/portal.repaserizeni.cz/web && php8.5 artisan schedule:run >> /dev/null 2>&1
   ```

12. Ověřit registraci a ruční bezpečný běh při vypnutém flagu:

   ```bash
   php8.5 artisan schedule:list
   php8.5 artisan notifications:dispatch --limit=1
   ```

   Command musí oznámit vypnuté doručování a nesmí změnit pending event.

13. Přihlásit se jako `AK_ADMIN`, otevřít `/admin/notifications`, ověřit vypnutý stav, počty, maskovaného příjemce, zakázku a absenci tajemství. AK operator a Polsko musí dostat 403.
14. Teprve po schválení SMTP testu nastavit správné credentials a `DISCUSSION_EMAIL_ENABLED=true`, znovu spustit `php8.5 artisan config:cache` a ověřit jednu jedinou testovací zprávu do kontrolovaného mailboxu.
15. Provést scénář dočasně chybného SMTP a retry podle checklistu. Ověřit audit `DISCUSSION_NOTIFICATION_SENT`, `DISCUSSION_NOTIFICATION_FAILED`, `DISCUSSION_NOTIFICATION_MANUAL_RETRY` a nezměněný workflow.
16. Zkontrolovat scheduler/worker logy, `PENDING/PROCESSING/FAILED`, stale locky a poslední úspěšné odeslání. Teprve potom ukončit servisní okno.

## Provozní kontrola

- Každou minutu musí běžet pouze `schedule:run`; samotný worker registruje Laravel scheduler s `withoutOverlapping(10)` a bez `onOneServer`.
- `PROCESSING` starší než `DISCUSSION_EMAIL_LOCK_MINUTES` se obnoví; opakovaně stale event je incident k šetření.
- Failed počet a nejstarší pending/next attempt monitorovat. Nikdy neopravovat příjemce nebo payload ručním SQL.
- Ruční retry dělat jen přes AK_ADMIN UI. Recipient-less event není retryovatelný.
- Import a Pohoda bridge zůstávají vypnuté, dokud je neaktivuje samostatná schválená fáze.

## Rollback

Preferovaný rollback je **aplikační bez rollbacku schématu**:

1. Nastavit `DISCUSSION_EMAIL_ENABLED=false` a rebuildnout config cache.
2. Pozastavit portálový cron nebo ponechat worker bezpečně no-op při vypnutém flagu.
3. Vrátit aplikaci na předchozí ověřený commit/tag a spustit odpovídající `composer install` a cache rebuild.
4. Zachovat tabulky `messages`, `message_attachments`, `message_reads`, `outbox_events` a všechna data. Nespouštět automaticky `migrate:rollback`, `reset`, `refresh`, `fresh` ani `db:wipe`.
5. Pokud starší kód neumí nové schéma používat, ponechat web v maintenance režimu a připravit auditovaný forward-fix. Obnova DB/private storage ze zálohy je až poslední incidentní možnost a vyžaduje rozhodnutí o ztrátě novějších zpráv.
6. Ověřit stav zakázek, zásilek, vratek a financí; rollback notifikací nesmí měnit workflow.

At-least-once omezení zůstává i během incidentu: před ručním resend ověřit, zda SMTP server předchozí pokus již nepřijal.
