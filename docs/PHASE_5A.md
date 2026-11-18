# Fáze 5A — doménové jádro diskuse

Fáze 5A přidává jednu společnou diskusi AUTOKLUBY ↔ Polsko ke každé zakázce. Zprávy a přílohy jsou append-only, přečtení se eviduje pro každého uživatele zvlášť a vytvoření zprávy ve stejné transakci zapíše outbox event pro pozdější notifikace. V této fázi se žádný e-mail neodesílá a SMTP se nepřipojuje.

## Datový model

Migrace `2026_11_18_000009_create_phase_5a_discussion.php` vytváří:

- `messages`: zakázka, autor, organizace autora, text, idempotency key, request hash a čas vytvoření;
- `message_attachments`: náhodný veřejný token, privátní disk a náhodná cesta, očištěný původní název, skutečný MIME, přípona, velikost a SHA-256;
- `message_reads`: unikátní dvojice zpráva–uživatel a první `read_at`;
- `outbox_events`: obecný transakční outbox se stavem, počtem pokusů, dostupností, deduplikačním klíčem a JSON payloadem.

Modely `Message` a `MessageAttachment` odmítají update i delete. Web nemá routu pro změnu nebo smazání zprávy. `READ_ONLY` uživatel diskusi vidí, ale novou zprávu vytvořit nesmí.

## Routy

Všechny routy používají middleware `auth`, `active`, `password.changed`:

- `GET /orders/{order}/discussion` — `orders.discussion.index`;
- `POST /orders/{order}/discussion` — `orders.discussion.store`;
- `POST /orders/{order}/discussion/read` — `orders.discussion.read`;
- `GET /message-attachments/{attachment}/download` — `message-attachments.download`.

Detail zakázky odkazuje na diskusi. Přímé otevření diskuse kontroluje `OrderPolicy::view`; příloha navíc prochází `MessageAttachmentPolicy`, `MessagePolicy` a stejnou autorizací zakázky. Nepovolená nebo neexistující příloha vrací 404. Polský uživatel proto vidí pouze diskuse zakázek dostupných přes existující `ProcessThread::visibleTo()` row-level scope.

Diskusní stránka nenačítá zákaznický vztah ani `business_snapshot`, a Polsku tak nezpřístupňuje systémová zákaznická PII. Volný text a obsah příloh jsou záměrně společná komunikace obou organizací a automaticky se nepřekládají ani obsahově neredigují.

## Přílohy

Výchozí disk `local` míří do `storage/app/private`, mimo `public/`; fyzická cesta používá UUID a nikdy se nevykresluje. Stažení probíhá pouze přes autorizovaný controller a odpověď používá `attachment`, `nosniff`, sandbox CSP a `private, no-store`.

Allowlist obsahuje PDF, JPEG a PNG. Server kontroluje platný upload, jedinou povolenou příponu, deklarovaný i skutečný MIME přes `fileinfo`, velikost, bezpečný původní název a checksum. Výchozí limit je 5 příloh po 10 MB, konfigurovaný přes `config/messages.php`.

Soubor se ukládá před DB transakcí. Při selhání validace se neukládá nic; při rollbacku se odstraní pouze konkrétní nově vytvořené UUID cesty. Testy používají unikátní sandbox pod systémovým temp adresářem a nikdy produkční private storage.

## Idempotence, přečtení a outbox

Každé odeslání má unikátní idempotency key. Request hash zahrnuje zakázku, autora, normalizovaný text a metadata/checksumy příloh. Opakování stejného požadavku vrátí původní zprávu; opětovné použití klíče s jiným obsahem je konflikt.

Autorova nová zpráva má ihned vlastní read záznam a dotaz `unreadFor()` současně vylučuje zprávy daného autora. Označení jako přečtené vkládá read-state pouze pro dosud nepřečtené zprávy dané zakázky.

Outbox event `discussion.message-posted.v1` vzniká atomicky se zprávou. Payload obsahuje pouze technické identifikátory a čas, nikoli text, zákaznická data nebo obsah příloh. Worker, retry a administrátorský přehled následně doplnila Fáze 5C popsaná v `PHASE_5C.md`.

## Bez vlivu na workflow

Služba diskuse nezapisuje do `orders`, `process_threads`, `thread_events`, stavových automatů ani finančního ledgeru. Zpráva tedy nemění stav, odpovědnou stranu, lock version, uzavření zakázky ani workflow historii.

## Ověření a známá omezení

`MessagePersistenceTest` ověřuje append-only model a vazby. `Phase5aDiscussionTest` pokrývá vytvoření a prázdnou zprávu, přímé URL, izolaci zakázek, polský row-level scope, zákaznická PII, read-only roli, bezpečnost a autorizaci příloh, podvržený MIME, překročení velikosti, dvojitou příponu, rollback souboru, idempotenci, read/unread počty, outbox bez e-mailu a nezměněné workflow.

Známá omezení Fáze 5A:

- diskusní stránka zobrazuje nejnovějších 200 zpráv bez stránkování;
- není implementován antivirus ani content moderation příloh;
- nejsou implementovány globální unread badge, interní notifikační centrum, outbox worker, retry ani e-mail;
- volný text a obsah příloh se automaticky nepřekládají ani nekontrolují na ručně vložené PII.

Testy se spouštějí pouze přes `.env.testing` a SQLite `:memory:`. Na serveru s produkční config/route cache musí testovací příkaz použít samostatné neexistující cesty `APP_CONFIG_CACHE` a `APP_ROUTES_CACHE` pod systémovým temp adresářem. Produkční migrace nebyla spuštěna a produkční DB ani storage nebyly změněny.

Finální `composer check` prošel: 318 testů, 1334 assertions.
