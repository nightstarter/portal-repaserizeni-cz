# Fáze 5 — závěrečná akceptace diskuse a notifikací

Datum auditu: 2026-11-18. Rozsah: Fáze 5A, 5B a 5C proti `transakcni_system_repase_MASTER_v17.md`, `transakcni_system_repase_IMPLEMENTACE_v18.md` a bezpečnostním pravidlům projektu.

Výsledek: **automatizovaná akceptace je zelená; produkční aktivace zůstává podmíněna deployment branami v `PHASE_5_DEPLOYMENT.md`**. Nebyl proveden push, deployment, produkční migrace, změna `.env`, crontabu, produkční DB ani private storage. Skutečný SMTP server nebyl kontaktován.

## Funkční celek

Akceptovaný celek poskytuje jednu společnou append-only diskusi ke každé zakázce:

- chronologické zprávy s autorem, organizací a časem;
- privátní PDF/JPEG/PNG přílohy přes autorizovaný download controller;
- per-user read-state, lokální a globální unread badge a frontu diskusí;
- deterministické cursorové stránkování podle `created_at, id`;
- úplné CZ/PL UI a lokalizovaný bezpečný e-mail;
- jeden idempotentní outbox požadavek pro každého aktivního autorizovaného příjemce druhé organizace;
- dohledatelný `no-recipient` event, pokud oprávněný příjemce neexistuje;
- worker s lease lockem, exponenciálním retry, maximem pokusů, recovery opuštěného locku a stavy `PENDING/PROCESSING/SENT/FAILED`;
- AK_ADMIN přehled s maskovaným příjemcem, sanitizovanou chybou a auditovaným retry.

Zpráva, příloha, odeslání e-mailu ani SMTP chyba nezapisují do `orders`, `process_threads`, `thread_events`, workflow stavů nebo finančního ledgeru. Regrese workflow, zásilek, vratek a financí prošly.

## Bezpečnostní audit

Výsledek: **bez otevřeného bezpečnostního blokátoru pro automatizovanou akceptaci**.

- Routy diskuse jsou pod middleware `auth`, `active`, `password.changed`. `DiscussionController` kontroluje `OrderPolicy::view`; cizí zakázka vrací 404. Samostatná route pro otevření jednotlivé zprávy neexistuje.
- Příloha prochází `MessageAttachmentPolicy`, následně `MessagePolicy` a autorizací zakázky; neexistující nebo cizí soubor vrací 404.
- `Order::visibleTo()` / `ProcessThread::visibleTo()` omezují Polsko na aktivně nebo historicky relevantní větev. Stejnou policy používá recipient resolver e-mailu.
- Diskusní detail, fronta ani e-mail nenačítají zákaznický snapshot. Testy odmítají jméno, e-mail, telefon, text zprávy, název přílohy, storage path a veřejný token v e-mailu.
- Text zprávy a názvy souborů se HTML escapují. Regrese pokrývá `<script>`, `<img onerror>` i aktivní název souboru.
- Allowlist obsahuje jen PDF, JPEG a PNG. Kontroluje se jediná přípona, deklarovaný i skutečný MIME, limit, bezpečný název a SHA-256. HTML, SVG, PHP, shell, dvojitá přípona, traversal, podvržený MIME a nadlimitní soubor jsou odmítnuty.
- Fyzický název je UUID a disk `local` míří do `storage/app/private`, mimo `public/`. HTML neobsahuje storage path; download vrací `nosniff`, sandbox CSP a `private/no-store`.
- Formuláře používají CSRF a idempotency key. Opakovaný submit blokuje klient a serverová unikátní idempotence je autoritativní.
- `READ_ONLY` formulář nevidí a server jeho zápis odmítá.
- SMTP výjimka se nezapisuje. `last_error` a audit obsahují pouze obecný bezpečný text a technická ID. Canary test ověřuje absenci tajemství v payloadu, `last_error`, auditu a administračním HTML. Ve verzovaných souborech není skutečné heslo nebo privátní klíč.
- Administraci notifikací povoluje pouze gate `manage-notifications` pro aktivní `AK_ADMIN` z AUTOKLUBY. AK operator i Polsko dostanou 403.

Volný text je sdílená komunikace. Systém jej automaticky neklasifikuje; uživatel proto může ručně vložit PII. To je známé provozní omezení, nikoli únik systémově načítaného zákaznického snapshotu.

## Outbox, retry a souběh

- Unikátní `deduplication_key` `discussion-email:{message_id}:{recipient_user_id}` znemožní duplicitní event pro tutéž kombinaci. Ruční retry mění tentýž řádek.
- Claim probíhá v krátké DB transakci přes `SELECT … FOR UPDATE`; stav se mění na `PROCESSING` s unikátním `lock_token`. Druhý worker čerstvý lease nepřevezme.
- Lease bez času nebo starší než `DISCUSSION_EMAIL_LOCK_MINUTES` se obnoví. Regrese pokrývá čerstvý i opuštěný lock.
- Pending event se vybírá jen při `next_attempt_at IS NULL` nebo `<= now()`. Prodleva je `base × 2^(attempts-1)` a po maximu vznikne `FAILED/failed_at`.
- Při `DISCUSSION_EMAIL_ENABLED=false` command skončí před claimem; event, attempts i termíny zůstanou beze změny a Mail facade se nepoužije.
- SMTP chyba vzniká až po commitu zprávy a nemůže zprávu rollbacknout. Test potvrzuje zachovanou zprávu a nezměněný workflow stav.
- Ruční retry selhaného recipient eventu je CSRF chráněný, auditovaný a idempotentní. `no-recipient` event nemá nefunkční retry tlačítko.

Externí SMTP nelze zahrnout do stejné atomické DB transakce. Doručení je proto **at-least-once**, nikoli exactly-once. Pokud proces spadne po přijetí mailu SMTP serverem a před zápisem `SENT`, obnovený worker může zprávu odeslat znovu.

## Migrace a MariaDB 10.11

Povinné pořadí je:

1. `2026_11_18_000009_create_phase_5a_discussion.php`;
2. `2026_11_18_000010_extend_outbox_events_for_discussion_notifications.php`;
3. `2026_11_18_000011_add_phase_5_query_indexes.php`.

`000009` pouze vytváří nové tabulky `messages`, `message_attachments`, `message_reads`, `outbox_events`. `000010` přidává nullable recipient/lease/retry/result sloupce a indexy. `000011` přidává pouze kompozitní indexy. Žádná migrace nemaže ani nepřepisuje existující řádek v aplikačních tabulkách.

Použité typy (`BIGINT`, `VARCHAR`, `TEXT`, `JSON`, `TIMESTAMP`), FK, nullable unique UUID a B-tree indexy jsou kompatibilní s MariaDB 10.11.14. MariaDB dovoluje více `NULL` v unikátním `lock_token`. Délky unikátních varchar klíčů jsou pod limitem InnoDB/utf8mb4.

Klíčová omezení a indexy:

- `messages.public_token`, `messages.idempotency_key`, `message_attachments.public_token`, `message_attachments.storage_path`, `outbox_events.event_uuid`, `outbox_events.deduplication_key` a `outbox_events.lock_token` jsou unikátní;
- `message_reads(message_id,user_id)` je unikátní a podporuje unread anti-join;
- `messages(order_id,created_at,id)` podporuje historii a latest message;
- `outbox_events(event_type,status,next_attempt_at,id)` a `(event_type,status,locked_at,id)` podporují due a stale-lock větev workeru;
- FK zpráv, příloh a reads používají `RESTRICT`; recipient user je nullable FK s `RESTRICT`.

DDL nad `outbox_events` může na neprázdné tabulce držet metadata lock a sestavení indexů spotřebuje I/O. Aktuální produkční stav má `000009`, `000010` i `000011` pending, takže musí být aplikovány v jednom řízeném servisním okně dříve, než aplikace začne zapisovat zprávy. Pokud by jiné prostředí již mělo 5A data, před migrací je nutný samostatný audit starých recipient-less eventů.

## Cache a výkon

`config:cache`, `route:cache` a `view:cache` byly úspěšně sestaveny s testovací konfigurací do unikátního adresáře pod `/tmp`; produkční cache se nezměnila.

`Phase5dPerformanceTest` porovnává malou a 37řádkovou datovou sadu. Počet SELECTů dashboardu/menu ani fronty diskusí nesmí růst s počtem zakázek; současně platí stropy 25 SELECTů pro dashboard a 12 pro frontu. Test také ověřuje názvy všech tří nových kompozitních indexů.

## Testovací bezpečnost a výsledky

Testy mají dvě nezávislé brány:

1. `composer test:guard` ověří konfiguraci před startem suite;
2. `tests/TestCase::setUp()` před každou testovací metodou znovu odmítne cokoli jiného než efektivní `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.

Finální `composer check` prošel: **338 testů, 1 567 assertions**. Obsahuje Composer validaci, Pint, PHP syntax audit a kompletní testovací sadu. Cílené výsledky:

- diskuse, přílohy, unread, row-level, PII, outbox, fake mail, worker/retry/admin a výkon: **29 testů, 306 assertions**;
- workflow, zásilky, vratky a finance: **73 testů, 486 assertions**.

Produkční read-only baseline před i po testech: 26 tabulek / 135 řádků. Produkční config cache (`ee332e…dcb9`) i jediný privátní report (`f94a11…0790`) měly před i po stejný SHA-256. Testovací storage nezanechala artefakty.

## Nalezené a opravené problémy

1. Neaktivní nebo chybějící cílová organizace mohla skončit bez outbox stopy. Opraveno idempotentním `no-recipient` eventem.
2. Test DB guard byl pouze suite-level. Doplněn fail-fast guard před každou testovací metodou.
3. Chyběl explicitní performance regression a kompozitní worker/history indexy. Doplněna migrace `000011` a query-count test.
4. Recipient-less failed event zobrazoval neproveditelné retry tlačítko. Tlačítko je nyní jen u eventu s neměnným příjemcem.
5. Chyběl explicitní regresní důkaz pro HTML/SVG/PHP/shell, XSS filename a SMTP exception canary. Testy byly doplněny.

## Ruční browser checklist — neprovedeno

Checklist se provede až po schváleném deploymentu na bezpečné testovací zakázce, nikoli nyní nad produkčními daty.

### Desktop CZ — AUTOKLUBY

- [ ] Přihlásit pojmenovaný účet AUTOKLUBY s češtinou a otevřít detail povolené testovací zakázky.
- [ ] Ověřit položku `Diskuse`, chronologii, barvy organizací, datum/čas a prázdný stav.
- [ ] Odeslat text a dvě povolené přílohy; při dvojkliku vznikne jediná zpráva.
- [ ] Stáhnout přílohu přes autorizovaný odkaz; URL ani HTML nesmí obsahovat storage path.
- [ ] Ověřit lokální i globální červený badge a zmizení po otevření diskuse.
- [ ] Ověřit frontu `/discussions`, nepřečtené první a odkaz do správné zakázky.

### Mobil 390 × 844

- [ ] Opakovat detail a formulář při viewportu přesně 390 × 844.
- [ ] Ověřit zalomení dlouhého textu/názvu, nulový horizontální overflow a dotykové akce nejméně 44 px.
- [ ] Nahrát a stáhnout přílohu, načíst starší stránku a vrátit se na novější bez duplicity.

### Polsko a READ_ONLY

- [ ] Polský účet vidí jen relevantní zakázku, polské překlady a žádné zákaznické jméno, e-mail, telefon, adresu nebo zálohu.
- [ ] Přímé URL cizí diskuse a cizí přílohy vrátí bezpečnou 404.
- [ ] `READ_ONLY` vidí povolenou diskusi, ale nemá formulář a přímý POST vrátí 403.

### SMTP vypnuté/zapnuté a retry

- [ ] S `DISCUSSION_EMAIL_ENABLED=false` odeslat jednu testovací zprávu: zpráva/unread fungují, outbox zůstane `PENDING`, nevznikne SMTP spojení a admin ukáže vypnuté doručování.
- [ ] Po schválení nastavit testovací SMTP mailbox, rebuildnout config cache a řízeně přepnout `DISCUSSION_EMAIL_ENABLED=true`.
- [ ] Odeslat právě jednu testovací zprávu; druhá strana dostane lokalizovaný e-mail bez textu/PII/příloh a odkaz po přihlášení otevře správnou diskusi.
- [ ] Dočasně nastavit chybný SMTP endpoint, odeslat testovací zprávu a ověřit `PENDING`, attempts, sanitizovaný `last_error` a budoucí `next_attempt_at`.
- [ ] Obnovit SMTP, použít AK_ADMIN `Zkusit znovu`, ověřit audit a jediné úspěšné `SENT/sent_at`.
- [ ] Ověřit, že během chyb ani retry se nezměnil workflow, větev, zásilka, vratka nebo finance zakázky.

## Známá omezení

- At-least-once crash okno může způsobit duplicitní e-mail; exactly-once SMTP se netvrdí.
- Není implementován antivirus, content moderation, provider delivery/bounce webhook ani automatická retence historických outbox řádků.
- Volný text a obsah příloh se automaticky nepřekládají ani nekontrolují na ručně vložené PII.
- Recipienti se materializují při vytvoření zprávy; později založený uživatel nedostane historickou notifikaci.
- Produkční browser, SMTP, cron a scheduler testy nebyly provedeny.
