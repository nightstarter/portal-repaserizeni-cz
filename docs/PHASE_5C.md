# Fáze 5C — e-mailové notifikace diskuse

Fáze 5C rozšiřuje existující transakční `outbox_events` z Fáze 5A. Nevytváří druhou frontu. Nová zpráva v téže DB transakci vytvoří jeden idempotentní outbox řádek pro každého aktivního a autorizovaného uživatele druhé organizace. Deduplikační klíč má tvar `discussion-email:{message_id}:{recipient_user_id}`.

## Příjemci a bezpečný obsah

Příjemci se vybírají pouze z druhé aktivní organizace. Každý kandidát musí být aktivní a projít existující `OrderPolicy::view`, včetně polského row-level scope. Autor ani uživatel bez přístupu se příjemcem nestane. Opakované materializování stejné zprávy nevytvoří duplicitu. Pokud neexistuje žádný oprávněný příjemce, vznikne idempotentní řádek `no-recipient`; worker jej definitivně uzavře jako selhaný a administrace tak stále ukáže dohledatelný výsledek pro každou zprávu.

E-mail používá preferovaný jazyk příjemce (`cs`/`pl`). Předmět obsahuje pouze číslo zakázky a informaci o nové zprávě. Tělo obsahuje bezpečný odkaz na autorizovanou diskusi a výzvu k přihlášení. Neobsahuje text zprávy, přílohy, zákaznická PII, QR token, storage path ani technickou výjimku.

## Worker, lease a retry

Příkaz:

```bash
php artisan notifications:dispatch --limit=50
```

Worker přebírá záznam v krátké DB transakci pomocí `SELECT … FOR UPDATE`, nastaví stav `PROCESSING`, jedinečný `lock_token`, `locked_at` a zvýší `attempts`. E-mail odešle až mimo transakci HTTP požadavku i mimo transakci vytvoření zprávy. MariaDB používá řádkový lock; SQLite `:memory:` používá stejnou větev kódu a serializovaný zápis v testech.

Úspěch nastaví `SENT`, `sent_at` a `processed_at`. Dočasná chyba vrátí řádek do `PENDING`, uloží pouze obecný sanitizovaný důvod a naplánuje `next_attempt_at` s prodlevou `base × 2^(attempts-1)`. Po dosažení maxima vznikne `FAILED` a `failed_at`. `PROCESSING` lease starší než `DISCUSSION_EMAIL_LOCK_MINUTES` lze bezpečně převzít jiným workerem. Audit eviduje úspěšné odeslání, definitivní selhání a ruční retry pouze pomocí technických ID, bez e-mailové adresy či SMTP výjimky.

Při `DISCUSSION_EMAIL_ENABLED=false` command skončí úspěšně před převzetím prvního řádku, Mail facade se nepoužije a outbox zůstane beze změny. Zprávy, přílohy a unread badge fungují dál.

## Scheduler

`routes/console.php` registruje `notifications:dispatch` každou minutu s `withoutOverlapping(10)`. `onOneServer` se nepoužívá. Přesný produkční cron je:

```cron
* * * * * cd /var/www/portal.repaserizeni.cz/web && php8.5 artisan schedule:run >> /dev/null 2>&1
```

## Administrace

Pouze `AK_ADMIN` má přístup k:

- `GET /admin/notifications` (`admin.notifications.index`);
- `POST /admin/notifications/{event}/retry` (`admin.notifications.retry`).

Přehled ukazuje počty `PENDING`, `PROCESSING`, `SENT`, `FAILED`, poslední úspěch, maskovaného příjemce, zakázku, pokusy, další termín a sanitizovanou chybu. Při vypnutém feature flagu zobrazuje výrazné upozornění. Ruční retry je CSRF chráněný, auditovaný a idempotentní; mění pouze stav selhaného řádku, nikoli příjemce nebo obsah.

## Konfigurace

Produkční secret musí před řízenou aktivací dodat:

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

Výchozí stav zůstává vypnutý. Hesla ani skutečné adresy nejsou ve verzovaných souborech.

## Migrace a nasazení

`2026_11_18_000010_extend_outbox_events_for_discussion_notifications.php` pouze rozšiřuje `outbox_events` o příjemce, retry/lease a výsledkové časy. Je kompatibilní s MariaDB 10.11.14 a SQLite. Produkční nasazení musí nejprve aplikovat pending migraci Fáze 5A `000009`, potom `000010`; tato implementace žádnou produkční migraci nespustila.

Přidání sloupců a indexů může na neprázdné tabulce krátce držet metadata lock. Před migrací je nutná záloha, kontrola dlouhých transakcí a servisní okno. Automatický rollback se po vzniku 5C outbox dat nedoporučuje, protože odstraní retry metadata; bezpečnější je kódový rollback se zachovaným schématem.

## Testy a známá omezení

`Phase5cDiscussionNotificationTest` používá výhradně SQLite `:memory:` a `Mail::fake`/mock. Pokrývá recipient scope, idempotenci, bezpečný CZ/PL mail, retry a exponenciální prodlevu, maximum pokusů, sanitizaci chyb, souběžný lease, recovery opuštěného locku, vypnutý flag, AK_ADMIN přístup, CSRF, audit a idempotentní ruční retry. Transakční rollback zprávy/outboxu zůstává pokrytý `Phase5aDiscussionTest`.

Finální `composer check` prošel nad izolovanou SQLite `:memory:` databází: **333 testů, 1 519 assertions**. Součástí běhu byly test-database guard, Pint, kontrola PHP syntaxe a kompletní testovací sada; žádný skutečný mailer nebyl kontaktován.

Známá omezení:

- SMTP je externí side effect; mezi přijetím serverem a zápisem `SENT` může proces spadnout, takže doručení je **at-least-once** a ve vzácném crash okně může příjemce dostat duplicitu.
- Není implementována provider-specific webhook evidence skutečného doručení nebo bounce processing.
- Administrace nemá hromadný retry ani automatické mazání historických `SENT` řádků.
- Produkční SMTP, cron a browser smoke test nebyly v rámci implementace aktivovány ani provedeny.
