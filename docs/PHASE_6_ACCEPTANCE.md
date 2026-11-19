# Fáze 6 – závěrečná akceptace reklamací a výkupu

Datum auditu: 19. 11. 2026. Rozsah: fáze 6A–6D proti `transakcni_system_repase_MASTER_v17.md`, `transakcni_system_repase_IMPLEMENTACE_v18.md` a bezpečnostním pravidlům projektu. Fáze 7 nebyla zahájena.

## Výsledek

Automatizovaná a statická akceptace prošla. `php8.5 composer.phar check` proběhl pouze s `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` a skončil výsledkem **399 testů / 1936 assertions**. Základní `Tests\TestCase::setUp()` a předřazený composer guard odmítnou každou testovací metodu mimo izolovanou SQLite `:memory:` databázi.

Produkční databáze, `.env`, cron ani private storage nebyly změněny. Read-only `php8.5 artisan migrate:status` ukázal migrace 000012 a 000013 jako `Pending`.

## Nalezené a opravené problémy

1. Zákaznická nabídka výkupu se dříve označila `BUYBACK_OFFER_SENT` už při zařazení do outboxu. Oprava ponechává workflow v `BUYBACK_CUSTOMER_OFFER_READY`; teprve po úspěšném návratu mail transportu se v jedné DB transakci označí outbox `SENT`, posune větev na `BUYBACK_OFFER_SENT` a přidá idempotentní systémový `thread_event`. Pending, disabled, retry i failed event stav nemění. Outbox payload byl současně zmenšen na ID doménové události a neobsahuje zákaznický e-mail, cenu ani číslo zakázky. Commit `e3605e2`.
2. Po zařazení nabídky UI stále nabízelo druhé zařazení, které backend správně odmítal. Eager-loaded vztah na outbox nyní zápisovou akci skryje bez N+1. Commit `5a12e36`.
3. Dvě souběžné tvorby výkupu se stejným idempotency key mohly v MariaDB při snapshotu skončit unikátním konfliktem místo replay. Po konfliktu se nyní mimo původní snapshot znovu načte existující objednávka a vrátí se pouze při shodném request hashi; jiný obsah zůstává odmítnut. Commit `22de901`.
4. Polský uživatel s přístupem k reklamaci jednoho kusu mohl zkusit QR jiného kusu stejné zakázky a dostat se do obecného fallbacku. Fallback CUSTOM_REBUILD nyní vyžaduje vlastní row-level oprávnění. Commit `f871472`.
5. Doplněny akceptační regrese pro indexy, nezávislost feature flagů a konstantní query-count administrace 100 outbox eventů. Commit `57f88c0`.

## Kritická posloupnost zákaznické nabídky

Ověřená posloupnost je:

`BUYBACK_FINAL_PRICE_APPROVED` → `BUYBACK_CUSTOMER_OFFER_READY` → outbox `PENDING/PROCESSING` → úspěšné přijetí zprávy mail transportem → atomicky outbox `SENT` + systémový event `DELIVER_BUYBACK_CUSTOMER_OFFER` + `BUYBACK_OFFER_SENT`.

- Frontování samo stav neposune.
- `BUYBACK_CUSTOMER_EMAIL_ENABLED=false`, SMTP výjimka, retry, pending ani failed event stav neposunou.
- `BUYBACK_CUSTOMER_ACCEPTED` lze zapsat až z `BUYBACK_OFFER_SENT`.
- Před odesláním worker znovu načte vztahy a ověří stav, ručně schválenou finální cenu, oprávnění schvalujícího a validní e-mail.
- Vazba doručení používá stabilní `outbox_events.id` a idempotency key `buyback-email-delivered:{event_id}`.

SMTP je z principu **at-least-once**. Pokud SMTP server zprávu přijme a proces selže před lokálním commitem, retry může odeslat duplikát. Implementace nepředstírá exactly-once doručení; stav `OFFER_SENT` znamená úspěšný návrat mail transportu, nikoli doručení do schránky zákazníka.

## Reklamace WARRANTY

- WARRANTY je větev `process_threads` pod původním `order_id` a konkrétním `item_id`; nemění globální stav objednávky ani jiné větve.
- Unikátní `(order_id, type, item_id, warranty_cycle)`, zámek položky a `active_warranty_thread_id` brání dvěma aktivním cyklům stejného kusu.
- Terminální Reklamace 1 se neupravuje; Reklamace 2 má nový thread, nové číslo a samostatné append-only události i časovou osu.
- Přechody AK ↔ Polsko provádí existující `ShipmentService`; command endpoint je neumí obejít. Osobní odběr zákazníkem nevytváří zásilku ani tracking.
- Interní důvod a zákaznické PII se polskému uživateli v queue, detailu, QR ani timeline neposílají.
- UI používá stejný action guard a state machine jako backend; přepravní kroky pouze odkazují do zásilkového workflow.

## Výkup BUYBACK

- Systémová objednávka má `source=SYSTEM_BUYOUT`, `order_type=BUYOUT`, `source_order_id=NULL` a transakční `VYK-RRRR-NNNNN`.
- Roční čítač má primární klíč `year`; `INSERT IGNORE` bezpečně založí první řádek a následné `SELECT ... FOR UPDATE` serializuje přidělení čísla. `orders.order_no` a creation idempotency key jsou unikátní.
- Zákaznické údaje jsou pouze v `order_customers`; Polsku se nenačítají.
- Cenové řádky jsou append-only, verzované a v minor units. Doporučení 70 % používá celočíselný výpočet, zachovává měnu polské nabídky, neprovádí konverzi a nikdy se samo nestane finální cenou.
- Finální cenu, potvrzení nabídky a customer outbox vyžaduje `BUYBACK_FINAL_PRICE_APPROVE`; výchozí oprávnění má AK_ADMIN. Platbu vyžaduje `BUYBACK_PAYMENT_CONFIRM`.
- Polsko může zapsat jen vlastní nabídku a polské provozní kroky; nevidí interní cenu, doporučených 70 %, finální cenu, zákaznickou nabídku ani její stav.
- Volba `RETAINED_AK` / polská větev je explicitní a terminální nebo jednosměrná; tichá změna je odmítnuta.
- Polská přeprava používá `ShipmentService`. Faktura je `BUYBACK_INVOICE_TO_POLAND`, tj. AUTOKLUBY → Polsko; soubor je private, náhrada verzovaná a platba append-only v ledgeru.

## Outbox, oprávnění a ochrana dat

Jeden worker `notifications:dispatch --limit=50` zpracovává doménové typy `discussion.message-posted.v1` (DISCUSSION_MESSAGE_EMAIL) a `BUYBACK_CUSTOMER_OFFER_EMAIL`. Claim používá řádkový zámek, unikátní lock token, due indexy, stale-lock recovery, exponenciální retry a maximální počet pokusů. Fresh processing lock se nepřebírá. Ruční retry je CSRF chráněný, idempotentní, auditovaný a pouze pro AK_ADMIN.

Feature flagy jsou nezávislé: vypnutý typ se neclaimuje a neblokuje druhý povolený typ. Chyby ukládají pouze generickou sanitizovanou zprávu; testy hledají SMTP secret v outboxu, auditu a HTML administrace. Administrace maskuje adresu a eager-loaduje vztahy.

Serverové policies/scopes chrání order, message, attachment, document, WARRANTY, BUYBACK, QR a command endpointy. Cizí objekty vracejí bezpečnou 404 tam, kde by 403 potvrzovala jejich existenci. READ_ONLY nezapisuje; nepřihlášený uživatel skončí na auth middleware. Přílohy a dokumenty kontrolují příponu, skutečné MIME, velikost, checksum a zakázané/dvojité názvy; HTML, SVG, PHP a spustitelné typy jsou odmítnuty. Storage path se nevystavuje.

Polské regresní testy prohledávají dostupné HTML a JSON odpovědi na jméno, e-mail, telefon, adresu, interní nabídku, finální cenu, stav zákaznické nabídky a interní důvod reklamace. E-mailové testy ověřují absenci PII, textu diskuse, příloh, storage path a interních nabídek. Auditní data obsahující interní důvod nemají polskou route ani view.

## Migrace 000012 a 000013

Statický audit pro MariaDB 10.11 prošel:

- pořadí je 000012 WARRANTY, potom 000013 BUYBACK;
- používají podporované typy, FK `RESTRICT`, pojmenované indexy a unikátní omezení;
- 000012 pouze rozšiřuje `process_threads` a `items`; existující řádky dostanou nullable vazby a default `warranty_lock_version=0`;
- 000013 vytvoří sekvenci a cenovou historii a přidá nullable sloupce objednávky;
- žádný `up()` nemaže ani nepřepisuje existující data;
- queue/history indexy byly ověřeny i regresním testem;
- první roční čítač je chráněn PK + `INSERT IGNORE` + `FOR UPDATE`; souběžný idempotentní replay má bezpečné zotavení;
- Laravel migration repository zajistí jednorázový běh; ruční opakování stejného `up()` mimo migrator není podporovaný deployment postup.

Produkční stav: obě migrace jsou `Pending`. MariaDB integrační concurrency test proti produkci nebyl proveden; kompatibilita byla ověřena staticky a chování funkčně na SQLite `:memory:`.

## Výkon a cache

Regrese porovnávají malou sadu se 100 řádky a dovolují růst nejvýše o jeden SELECT. Prošly tyto limity:

- dashboard/menu WARRANTY: ≤ 25 SELECTů;
- WARRANTY queue 100 větví: ≤ 16;
- detail s více cykly: ≤ 25;
- dashboard/menu BUYBACK: ≤ 28;
- BUYBACK queue 100 výkupů: ≤ 18;
- detail s cenami, zásilkami, dokumenty a ledgerem: ≤ 22;
- administrace 100 outbox eventů: ≤ 12;
- diskusní dashboard/queue: ≤ 25 / ≤ 12.

`config:cache`, `route:cache` a `view:cache` proběhly úspěšně v testovacím prostředí a následný `optimize:clear` cache odstranil.

## Ruční browser checklist – neprovedeno

Následující kroky jsou určeny až pro kontrolované následné ověření; nyní nebyla vytvořena produkční data ani spuštěn produkční browser test:

- [ ] Ověřit prázdnou frontu Reklamace a Výkup na desktopu v CZ i PL.
- [ ] Opakovat fronty a detail v rozměru 390 × 844 bez horizontálního přetékání.
- [ ] AK_ADMIN: založit Reklamaci 1, projít variantu odeslání zákazníkovi a samostatně variantu osobního odběru.
- [ ] Po terminálním ukončení založit Reklamaci 2 a ověřit nezměněnou časovou osu Reklamace 1.
- [ ] AK_OPERATOR: ověřit provozní akce a zákaz finální ceny/platby.
- [ ] Polsko: ověřit jen přidělené reklamace/výkupy, vlastní nabídku a absenci všech PII/cen zákazníka.
- [ ] READ_ONLY: ověřit absenci všech formulářů a odmítnutí přímých POST endpointů.
- [ ] Založit systémový výkup a ověřit formát `VYK-RRRR-NNNNN`.
- [ ] Zadat více polských nabídek, doporučení 70 % a ruční finální cenu AK_ADMIN.
- [ ] S `BUYBACK_CUSTOMER_EMAIL_ENABLED=false` zařadit nabídku: outbox musí být pending a stav stále READY.
- [ ] V kontrolovaném testovacím mailboxu později flag zapnout, spustit worker a ověřit přechod na OFFER_SENT až po přijetí SMTP.
- [ ] Ověřit ruční přijetí zákazníkem až po OFFER_SENT.
- [ ] Projít větev „zůstává AUTOKLUBY“ bez polské zásilky/faktury.
- [ ] Projít větev „vykupuje Polsko“ přes ShipmentService, upload/náhradu faktury a oprávněné potvrzení platby.
- [ ] Ověřit zakázané přímé URL zakázky, threadu, QR, přílohy a dokumentu pro Polsko i nepřihlášeného uživatele.
- [ ] Ověřit administraci obou outbox typů, maskování příjemce a ruční retry.

## Známá omezení

- SMTP doručení je at-least-once; vzdálené exactly-once nelze garantovat.
- Úspěch transportu potvrzuje přijetí SMTP serverem, ne doručení do inboxu ani přečtení.
- Produkční MariaDB migrace a souběžný test nebyly spuštěny.
- Produkční cron, SMTP a browser workflow nebyly ověřeny ani měněny.
- Veřejný zákaznický portál, veřejné přijetí nabídky, automatické měnové konverze a automatické potvrzení platby nejsou součástí fáze 6.
