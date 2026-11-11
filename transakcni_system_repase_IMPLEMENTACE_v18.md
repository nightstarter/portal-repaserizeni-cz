# Transakční systém repasovaných řízení – IMPLEMENTACE v18

## 0. Účel dokumentu

Tento dokument je technické implementační zadání pro nový systém na:

- produkční URL: `https://portal.repaserizeni.cz`,
- zdrojový repozitář: `https://github.com/nightstarter/portal-repaserizeni-cz.git`.

Obchodní a procesní pravidla jsou popsána v souboru:

- `transakcni_system_repase_MASTER_v17.md`.

Oba dokumenty se musí číst společně a celé před zahájením implementace.

### Priorita pravidel

1. `MASTER_v17` je autorita pro význam procesů, rolí a uživatelských akcí.
2. `IMPLEMENTACE_v18` je autorita pro architekturu, bezpečnost, integrace, databázi, nasazení a pořadí vývoje.
3. Pokud jsou dokumenty v rozporu, implementace se nesmí domyslet potichu. Rozpor se zapíše do `docs/DECISIONS.md` a vyžádá se rozhodnutí vlastníka projektu.

### Co tento projekt není

- Není to úprava starého souboru `objednavky.php`.
- Není to modul starého PrestaShopu.
- Není to veřejné zpřístupnění interního HPSERVERU.
- Není to jedna tabulka objednávek s jedním globálním stavem.
- Není to jednorázový skript bez migrací, testů a auditu.

---

## 1. Potvrzená provozní rozhodnutí

1. Nová aplikace běží odděleně na `portal.repaserizeni.cz`.
2. Hosting má Apache, SSH, MySQL a cron.
3. Composer bude na hostingu k dispozici.
4. Aktuální PHP je 8.2; cílem před produkčním spuštěním je PHP 8.4 nebo novější podporovaná verze kompatibilní se zvoleným frameworkem.
5. Aplikace dostane novou prázdnou MySQL databázi.
6. Starý e-shop zůstává na PHP 5.4 a nebude rozšiřován o aplikační logiku portálu.
7. Portálu lze povolit přístup do MySQL e-shopu podle veřejné IP adresy portálu.
8. Přístup portálu k databázi e-shopu bude pouze pro čtení a jen do nutných tabulek/sloupců.
9. Na interním HPSERVERU lze spouštět naplánovanou úlohu, která komunikuje odchozím HTTPS s portálem.
10. Systém začíná novými objednávkami od data ostrého startu. Historická data a rozpracované případy se nemigrují.
11. E-mail se odesílá přes vlastní SMTP.
12. Vývoj probíhá po fázích.
13. Zdrojový kód se verzováním spravuje v uvedeném GitHub repozitáři.
14. POLSKO je v první verzi jedna organizace s více pojmenovanými uživatelskými účty. Kraft/Argo jsou routing a atribut zakázky, nikoli samostatní tenanté. Datový model však nesmí budoucí rozdělení dodavatelů znemožnit.

---

## 2. Cílová architektura

```text
                         INTERNET

  AUTOKLUBY uživatelé ─────┐
                            ├── HTTPS ── portal.repaserizeni.cz
  POLSKO uživatelé ─────────┘             ├─ PHP aplikace
                                          ├─ vlastní MySQL
                                          ├─ privátní úložiště dokumentů
                                          ├─ SMTP
                                          └─ cron / fronta úloh
                                                   ▲
                         read-only MySQL            │ odchozí HTTPS
  starý e-shop PHP 5.4 ────────────────────────────┤
                                                   │
                              firemní síť           │
  Pohoda SQL ── interní konektor na HPSERVERU ──────┘
```

### Bezpečnostní hranice

- Veřejný server se nikdy nepřipojuje přímo k SQL Serveru Pohody v interní síti.
- Na firewallu firmy se kvůli portálu neotvírá příchozí spojení k HPSERVERU ani k Pohodě.
- Interní konektor zahajuje pouze odchozí HTTPS spojení na portál.
- Databáze portálu je oddělená od databáze PrestaShopu.
- PrestaShop je zdroj objednávkových dat, nikoli úložiště workflow portálu.
- Portál nesmí zapisovat do tabulek PrestaShopu přes importní databázový účet.

---

## 3. Technologický základ

### Backend

- PHP framework: aktuální stabilní Laravel kompatibilní s produkční verzí PHP.
- Před instalací frameworku ověřit jeho podporovanou PHP verzi.
- Pokud aktuální stabilní framework již nepodporuje PHP 8.2, nejprve povýšit PHP; neinstalovat kvůli tomu zastaralý framework.
- Composer dependencies musí být uzamčené v `composer.lock` a commitnuté.
- Databázové změny výhradně verzovanými migracemi.
- Business logika patří do aplikačních/doménových služeb, nikoli do controllerů a Blade šablon.
- Stavové změny probíhají transakčně a přes explicitní aplikační akce.

### Frontend

- Server-rendered responzivní UI, přednostně Blade + Bootstrap.
- Nepoužívat SPA framework bez prokazatelné potřeby.
- Mobilní detail po QR je prvotřídní použití, nikoli zmenšená desktopová tabulka.
- Všechny texty od začátku přes překladové slovníky `cs` a `pl`.
- Do databáze ukládat stabilní kódy, nikdy přeložené názvy stavů.

### Databáze a čas

- MySQL 8 nebo aktuální MariaDB podporovaná hostingem.
- Aplikační databáze a spojení používají `utf8mb4`.
- Časová zóna aplikace: `Europe/Prague`.
- Časy v databázi ukládat konzistentně v UTC; v UI zobrazovat podle `Europe/Prague`, pokud nebude později zavedena uživatelská zóna.
- Peněžní hodnoty ukládat jako `DECIMAL`, nikdy jako `FLOAT`.
- Interní primární klíče mohou být BIGINT nebo ULID. Veřejné tokeny nesmí být odhadnutelné sekvenční ID.

### Doporučená struktura repozitáře

```text
app/
  Domain/
  Application/
  Http/
  Integrations/
config/
database/
  migrations/
  seeders/
docs/
  DECISIONS.md
  DEPLOYMENT.md
  INTEGRATIONS.md
  STATE_MACHINES.md
resources/
  lang/cs/
  lang/pl/
  views/
routes/
storage/
tests/
  Feature/
  Unit/
```

Konkrétní strukturu lze přizpůsobit konvencím zvolené verze frameworku, ale doménová logika nesmí být rozptýlená po controllerech.

---

## 4. Konfigurace a tajné údaje

V repozitáři smí být pouze `.env.example`. Produkční `.env` se nikdy necommituje.

Minimální konfigurační skupiny:

```dotenv
APP_NAME="Repase řízení"
APP_ENV=production
APP_URL=https://portal.repaserizeni.cz
APP_LOCALE=cs
APP_FALLBACK_LOCALE=cs
APP_TIMEZONE=Europe/Prague

DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

LEGACY_SHOP_DB_HOST=
LEGACY_SHOP_DB_PORT=3306
LEGACY_SHOP_DB_DATABASE=
LEGACY_SHOP_DB_USERNAME=
LEGACY_SHOP_DB_PASSWORD=
LEGACY_SHOP_IMPORT_ENABLED=false
LEGACY_SHOP_IMPORT_FROM=
LEGACY_SHOP_MIN_ORDER_ID=

POHODA_BRIDGE_ENABLED=false
POHODA_BRIDGE_KEY_ID=
POHODA_BRIDGE_SECRET=

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Repase řízení"
```

Pravidla:

- Do logů se nesmí zapisovat hesla, API klíče, cookies, celé autorizační hlavičky ani celé osobní údaje.
- Produkční chybové výpisy nesmí zobrazovat stack trace uživateli.
- Tajné údaje nalezené ve starých PHP souborech se nekopírují. Pro nový portál se vytvoří nové účty a nové klíče.
- Každá integrace má vlastní účet s minimálními právy a možností samostatné rotace.

---

## 5. Autentizace, organizace a oprávnění

### Organizace

Počáteční seed:

- `AUTOKLUBY`
- `POLAND`

### Uživatelé

- Každý pracovník má vlastní účet; sdílené přihlášení není povoleno.
- Uživatel má organizaci, roli, jazyk, stav aktivní/neaktivní a datum posledního přihlášení.
- Administrátor může účet deaktivovat bez smazání historie.
- Připravit možnost vynutit změnu hesla a později zapnout 2FA.
- Hesla ukládat výhradně moderním frameworkovým password hashem.
- Přihlášení chránit rate limitem a bezpečnou správou session.

### Role první verze

- `AK_ADMIN` – plná práva, správa uživatelů, audit, override.
- `AK_OPERATOR` – běžné provozní akce AUTOKLUBY.
- `PL_MANAGER` – polské workflow, finance a dokumenty v povoleném rozsahu.
- `PL_OPERATOR` – provozní polské akce bez administrace uživatelů.
- případně `READ_ONLY` – auditní/účetní náhled bez změn.

Oprávnění se kontrolují na serveru pomocí policies/gates. Skrytí tlačítka v UI není bezpečnostní kontrola.

### Viditelnost Polska

- Polsko vidí pouze zakázky, u kterých má přidělenou aktivní nebo historicky oprávněnou procesní větev.
- Zákaznické osobní údaje zobrazovat Polsku pouze v minimálním rozsahu nutném pro proces. Výchozí stav je nezobrazovat adresu, telefon, e-mail ani interní poznámky AUTOKLUBY.
- Polsko nesmí měnit zákazníka, prodejní cenu, sklad Děčín, produktová přednastavení ani interní pole AUTOKLUBY.
- Veškeré administrátorské override akce musí vyžadovat důvod a vytvořit audit.

---

## 6. Datový model

Názvy tabulek lze přizpůsobit konvencím frameworku, význam objektů ale musí zůstat zachován.

### Základní tabulky

#### `organizations`

- `id`, `code`, `name`, `active`, timestamps.

#### `users`

- organizace, jméno, e-mail, role, preferovaný jazyk, heslo, aktivita, poslední přihlášení, timestamps.

#### `orders`

- interní ID,
- `order_no` – hlavní viditelný identifikátor,
- `source` (`ESHOP`, `SYSTEM_BUYOUT`),
- externí `source_order_id`,
- typ zakázky,
- technický typ (`WORM`, `HYDRAULIC`, `EPS`),
- routing (`KRAFT`, `ARGO`),
- produktový kód a snapshot názvu,
- VIN a vozidlo,
- způsob předání zákazníkovi,
- stav ověření produktu,
- datum importu a snapshot obchodních údajů,
- `closed_at`, timestamps.

Unikátní omezení minimálně na `(source, source_order_id)` a podle reality také na `order_no`.

#### `order_customers`

- 1:1 snapshot zákazníka v okamžiku importu,
- jméno/firma, e-mail, telefon a nutné adresní údaje,
- data nejsou průběžně přepisována změnami účtu v e-shopu.

#### `items`

- interní ID,
- `order_id`,
- role fyzického kusu,
- veřejný náhodný QR token,
- aktuální lokalita,
- kontrolní výsledek,
- technické atributy a poznámka,
- timestamps.

QR URL nepoužívá samotné sekvenční `item_id`. Použije náhodný token, například `/q/{token}`. Po přihlášení token otevře zakázku a zvýrazní konkrétní kus.

#### `process_threads`

- `order_id`,
- typ větve,
- stabilní stavový kód,
- odpovědná organizace,
- volitelný dodavatel/routing,
- pořadí cyklu reklamace,
- ETA,
- datum dokončení,
- verze záznamu pro ochranu proti souběžné změně,
- timestamps.

Typy větví podle MASTER v17:

- `STOCK_REQUEST`
- `CUSTOM_REBUILD`
- `CORE_RETURN`
- `STOCK_REPLENISHMENT`
- `WARRANTY`
- `BUYOUT`

#### `thread_events`

Neměnný provozní deník akcí:

- větev, zakázka, akce,
- původní a nový stav,
- actor user/organization/system,
- důvod, metadata,
- datum provedení.

Aktuální stav je v `process_threads`, historie v `thread_events`. Obojí se zapisuje v jedné databázové transakci.

### Zásilky

#### `shipments`

- veřejné číslo zásilky portálu,
- směr,
- typ přepravy/dopravce,
- tracking nullable,
- stav,
- odesílatel/příjemce jako organizace nebo zákazník,
- datum přípravy, odeslání, doručení,
- chyba a poznámka,
- creator, timestamps.

#### `shipment_items`

- zásilka,
- fyzický kus,
- související procesní větev,
- timestamps,
- unikátní ochrana proti dvojímu aktivnímu vložení stejného kusu.

`SVOZ_AUTOKLUBY` je typ přepravy bez povinného trackingu.

### Diskuse

#### `messages`

- zakázka, autor, organizace autora, text, timestamps, případně smazání pouze logickým příznakem a auditem.

#### `message_attachments`

- metadata bezpečně uloženého souboru.

#### `message_reads`

- zpráva, uživatel, `read_at`.

Diskuse nikdy sama nemění stav.

### Dokumenty a finance

#### `documents`

- zakázka, volitelně větev,
- typ dokumentu,
- bezpečná cesta/identifikátor souboru,
- původní název, MIME, velikost, checksum,
- číslo dokumentu, datum, měna, částka,
- nahrál uživatel, timestamps.

#### `financial_entries`

- zakázka, volitelně větev,
- typ finanční hodnoty/události,
- částka a měna,
- stav,
- důvod krácení,
- vazba na dokument,
- actor, timestamps.

Povinné typy pokrývají nákupní cenu, původní zálohu, vrácenou zálohu, krácení, dobropis, výkupní cenu Polska, finální zákaznickou cenu a platbu Polska.

#### `product_price_changes`

- produktový kód,
- stará a nová nákupní cena,
- měna,
- platnost od,
- uživatel Polska,
- stav synchronizace do e-shopu,
- timestamps.

Změna ceny nikdy zpětně nepřepisuje snapshot existující zakázky.

### Audit a integrace

#### `audit_log`

- actor, organizace, zakázka,
- objekt a jeho ID,
- akce,
- staré/nové hodnoty v bezpečně filtrovaném JSON,
- důvod,
- IP a user-agent v přiměřeném rozsahu,
- timestamp.

Audit je append-only pro běžné uživatele.

#### `integration_events`

- zdroj integrace,
- externí unikátní event ID,
- typ,
- hash payloadu,
- bezpečně filtrovaný payload nebo reference na něj,
- stav zpracování, počet pokusů, chyba, timestamps.

Unikátní klíč `(integration, external_event_id)` zajišťuje idempotenci.

#### `integration_checkpoints`

- integrace,
- poslední bezpečně zpracovaný kurzor/ID/čas,
- timestamps.

#### `stock_snapshots`

- produktový kód,
- dostupné množství,
- rezervace a případné další potřebné hodnoty,
- zdroj `POHODA`,
- čas platnosti zdrojových dat a čas přijetí.

#### `outbox_events`

- požadavky určené pro bezpečné asynchronní integrace, například přecenění,
- typ, payload, stav, pokusy, dostupnost od, timestamps.

---

## 7. Stavový automat a aplikační akce

### Zásada

Uživatel nikdy neposílá libovolný cílový stav. Posílá příkaz typu `ConfirmPolandReceipt`. Server:

1. načte zakázku a větev se zámkem/ochranou proti souběhu,
2. ověří roli a organizaci,
3. ověří aktuální stav,
4. ověří povinná pole,
5. v transakci změní stav, zapíše událost a audit,
6. po commitu naplánuje notifikaci nebo integrační událost.

### Stabilní akční kódy

Minimálně:

- `CONFIRM_AK_RECEIPT`
- `SEND_TO_POLAND`
- `CONFIRM_PL_RECEIPT`
- `MARK_REPAIRED_PL`
- `SEND_TO_AUTOKLUBY`
- `CONFIRM_AK_RETURN_RECEIPT`
- `SEND_TO_CUSTOMER`
- `MARK_READY_PICKUP`
- `CONFIRM_CUSTOMER_PICKUP`
- `CONFIRM_PL_STOCK_AVAILABLE`
- `SET_PL_STOCK_ETA`
- `CONFIRM_CORE_RECEIPT_AK`
- `CONFIRM_CORE_RECEIPT_PL`
- `APPROVE_FULL_DEPOSIT_RETURN`
- `APPROVE_REDUCED_DEPOSIT_RETURN`
- `UPLOAD_INVOICE`
- `UPLOAD_CREDIT_NOTE`
- `CONFIRM_CREDIT_PAID`
- `CREATE_WARRANTY_CYCLE`
- `SUBMIT_PL_BUYOUT_PRICE`
- `CONFIRM_FINAL_CUSTOMER_BUYOUT_PRICE`
- `ADMIN_OVERRIDE`

Přesná tabulka povolených přechodů pro každý typ větve se sepíše před implementací workflow do `docs/STATE_MACHINES.md`. Musí vycházet z MASTER v17 a být pokrytá unit testy.

### Současné změny

- Dvě osoby nesmí stejnou větev posunout dvakrát.
- Akce musí být idempotentní při opakovaném HTTP požadavku.
- Pro mutační formuláře používat CSRF ochranu a jednorázový/idempotency klíč tam, kde hrozí opakované odeslání.
- Při konfliktu zobrazit aktuální stav a neprovést částečný zápis.

### Odvozené hodnoty

`Kdo je na tahu`, `Další akce`, KPI a úplné uzavření zakázky se odvozují z aktivních větví a neukládají se jako ručně editovatelný univerzální stav objednávky.

Zakázka se uzavře teprve po dokončení všech povinných větví a finančních kroků daného typu zakázky.

---

## 8. Import objednávek ze starého e-shopu

### Připojení

- Samostatné MySQL připojení `legacy_shop`.
- Firewall databáze povolí pouze veřejnou IP portálu.
- Samostatný uživatel databáze s `SELECT` pouze na nezbytné tabulky.
- Zakázat `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `CREATE`, `DROP`, `FILE` a administrátorská oprávnění.
- Pokud hosting e-shopu umožní TLS pro MySQL, vyžadovat ověření certifikátu.
- Heslo musí být nové a nesmí být shodné se starými účty nalezenými v legacy kódu.

### Importní pravidla

- Import spouští cron v krátkém intervalu, například každé 2–5 minut.
- Importují se pouze objednávky splňující obchodní podmínku „uhrazená objednávka“ podle skutečných stavů e-shopu.
- Import začne od explicitního `LEGACY_SHOP_IMPORT_FROM` a/nebo `LEGACY_SHOP_MIN_ORDER_ID` nastaveného při ostrém startu.
- Starší objednávky se automaticky nedohledávají ani nemigrují.
- Jedna zdrojová objednávka se importuje právě jednou díky unikátnímu `(source, source_order_id)`.
- Opakovaný import aktualizuje pouze výslovně povolená zdrojová pole před zahájením workflow; nesmí přepisovat ruční provozní data portálu.
- Import vytvoří snapshot zákazníka, produktu, ceny a rozhodných atributů objednávky.
- Typ zakázky a počáteční větve vytvoří samostatná tovární/doménová služba, nikoli SQL importér.
- Neúplná nebo neznámá objednávka přejde do integrační chyby pro AUTOKLUBY; nesmí být potichu přeskočena.

### Mapování legacy tabulek

Před ostrým importem vytvořit `docs/INTEGRATIONS.md` s ověřeným mapováním:

- zdroj order ID a `order_no`,
- podmínka úhrady,
- zákazník a adresa,
- položka a kód produktu,
- VIN a vozidlo,
- typ zakázkové/skladové repase,
- technický typ,
- Kraft/Argo,
- způsob předání,
- cena a záloha.

Mapování se má ověřit proti stávajícímu kódu v `objednavky.php`, ale nový portál nesmí staré implementační zkratky automaticky považovat za správný doménový model.

### Přecenění

Protože importní účet je read-only, přecenění se ukládá do `outbox_events`. Samostatný omezený konektor později změnu převezme a provede na straně e-shopu. Přímé zápisy portálu do PrestaShop tabulek nejsou součástí první fáze.

---

## 9. Integrace Pohody přes interní konektor

### Umístění

Konektor běží na HPSERVERU nebo jiném interním stroji, který:

- může číst SQL databázi Pohody,
- může provádět odchozí HTTPS na `portal.repaserizeni.cz`,
- může běžet z Windows Task Scheduleru.

Konektor není součástí veřejného document rootu.

### Směr komunikace

- HPSERVER → portál: snapshoty skladové dostupnosti a potvrzení zpracovaných příkazů.
- Portál → HPSERVER se neotvírá jako příchozí síťové spojení.
- Pokud bude potřeba předat požadavek směrem k Pohodě, konektor si jej sám stáhne z autentizovaného outbox endpointu a po zpracování potvrdí výsledek.

### Zabezpečení API

- Vždy HTTPS.
- Samostatný bridge key ID a tajný klíč.
- Požadavek podepsat HMAC nad metodou, cestou, timestampem, nonce/event ID a hashem těla.
- Server odmítne starý timestamp, opakovaný event ID, chybný podpis a neznámý key ID.
- Porovnání podpisu constant-time funkcí.
- IP allowlist lze použít jako další vrstvu, nikoli jako jedinou autentizaci.
- Každý přijatý event je idempotentní.

### Minimální API

Konkrétní prefix může být `/api/internal/v1`:

- `POST /stock-snapshots` – dávka skladových hodnot z Pohody,
- `GET /commands` – konektor si stáhne čekající povolené požadavky,
- `POST /commands/{id}/result` – potvrdí úspěch nebo chybu,
- `POST /heartbeat` – verze konektoru a čas posledního úspěšného běhu.

Portál musí na dashboardu AUTOKLUBY zobrazit stáří poslední úspěšné synchronizace. Zastaralá dostupnost se nesmí tvářit jako aktuální.

### Chování při výpadku

- Konektor i portál používají retry s postupným prodlužováním intervalu.
- Jedna chybná položka nesmí zablokovat celou dávku.
- Chyby jsou dohledatelné bez logování tajných údajů.
- Opakované zaslání stejné dávky nesmí vytvořit duplicity.

---

## 10. Dokumenty a soubory

- Soubory ukládat mimo veřejný `public/` adresář nebo do privátního object storage.
- Stahování probíhá přes autorizovaný controller po kontrole oprávnění k zakázce.
- Nepoužívat uživatelský název jako fyzický název souboru.
- Povolit jen potřebné typy, kontrolovat MIME i příponu, velikost a generovat checksum.
- Zakázat spuštění nahraných PHP/HTML/SVG souborů.
- Faktury, dobropisy, fotografie a přílohy diskuse mají konfigurovatelné limity.
- Smazání důležitého dokumentu je pouze oprávněná auditovaná akce; vhodnější je logické zneplatnění a nahrání nové verze.

---

## 11. E-mail a notifikace

- Každá nová zpráva v diskusi vytvoří interní notifikaci a e-mail relevantním uživatelům druhé strany.
- E-mail se neposílá uvnitř databázové transakce HTTP požadavku.
- Použít databázovou frontu nebo outbox a cron worker.
- Chyba SMTP nesmí vrátit zpět platnou provozní akci.
- E-mail obsahuje bezpečný odkaz na zakázku; pro zobrazení je vždy nutné přihlášení.
- E-mail nemá obsahovat citlivější zákaznická data ani přílohy, pokud to není výslovně schválené.
- Neúspěšné odeslání má retry a přehled pro administrátora.

---

## 12. QR a mobilní provoz

- QR odkaz vede na HTTPS URL s náhodným tokenem.
- Nepřihlášený uživatel je přesměrován na přihlášení a po něm zpět na cílový detail.
- Samotná znalost QR tokenu neopravňuje uživatele k zakázce.
- Token lze auditovaně zneplatnit a vygenerovat znovu.
- Mobilní obrazovka prioritně ukazuje „Co mám udělat teď“ a pouze povolené akce.
- Skenování více kusů do jedné zásilky musí zabránit duplicitám, špatnému směru a kusu v jiné aktivní zásilce.
- Tiskový výstup štítku připravit jako HTML/PDF; konkrétní protokol tiskárny není podmínkou prvních fází.

---

## 13. Dashboardy a hledání

### AUTOKLUBY

Implementovat KPI a filtry z MASTER v17 nad procesními větvemi, ne nad jedním stavem objednávky.

### POLSKO

Polské fronty musí obsahovat pouze relevantní zakázky a jednu hlavní další akci na řádek.

### Vyhledávání

Minimálně:

- `order_no`,
- VIN,
- kód produktu,
- tracking,
- zákazník pro oprávněné uživatele.

Vyhledávání musí respektovat row-level oprávnění. Polský uživatel nesmí pomocí hledání ani odhadu URL zjistit cizí zakázku nebo zákaznické údaje.

---

## 14. Audit, logování a provozní dohled

- Auditovat všechny změny vyjmenované v MASTER v17.
- Security log oddělit od uživatelské časové osy.
- Logovat integrační běhy, počet načtených/importovaných/chybných záznamů a dobu běhu.
- Přidat health endpoint bez citlivých detailů.
- Administrátor vidí poslední úspěšný import e-shopu, poslední Pohoda heartbeat, stav fronty a poslední chyby.
- Chyba aplikace má korelační ID zobrazené uživateli a dohledatelné v logu.
- Produkční logy rotovat a nastavit jejich retenci.

---

## 15. Apache a produkční nasazení

### Document root

Apache VirtualHost musí směřovat výhradně do frameworkového adresáře `public/`, například:

```apache
DocumentRoot /cesta/k/portal-repaserizeni-cz/public
```

`.env`, `vendor`, `storage`, zdrojové soubory a dokumenty nesmí být přímo dostupné přes HTTP.

### Povinné minimum

- platný TLS certifikát a přesměrování HTTP → HTTPS,
- produkční debug vypnutý,
- bezpečné session cookies (`Secure`, `HttpOnly`, odpovídající `SameSite`),
- běhové adresáře zapisovatelné jen nutným uživatelem,
- zakázaný directory listing,
- správné security headers,
- limit velikosti uploadů sladěný v PHP, Apache i aplikaci,
- záloha databáze a privátních dokumentů,
- ověřená obnova ze zálohy.

### Doporučené crony

Konkrétní příkazy se upraví podle frameworku a cesty:

```cron
* * * * * php /cesta/artisan schedule:run
* * * * * php /cesta/artisan queue:work --stop-when-empty
```

Na sdíleném hostingu lze worker spouštět cronem. Na VPS je vhodnější dlouho běžící worker pod process supervisorem.

### Deployment

`docs/DEPLOYMENT.md` musí obsahovat opakovatelný postup:

1. maintenance režim podle potřeby,
2. stažení konkrétního commitu/tagu,
3. `composer install` bez dev balíčků a s optimalizací,
4. migrace s předchozí zálohou,
5. cache konfigurace a routes,
6. restart workerů,
7. smoke test,
8. návrat z maintenance režimu,
9. popsaný rollback aplikace i databázové změny.

Nasazení se neprovádí editací produkčních PHP souborů přes webový editor.

---

## 16. Vývojové fáze

Každá fáze musí skončit testy, aktualizací dokumentace a samostatným commitem nebo sadou logických commitů. Následující fáze nesmí zakrýt neuzavřené kritické chyby předchozí fáze.

### Fáze 0 – bootstrap a bezpečný základ

- inicializovat Git repozitář/projekt v uvedeném GitHub repozitáři,
- framework, `.env.example`, MySQL spojení,
- Apache/public document root dokumentace,
- CI pro testy a statickou kontrolu,
- organizace, uživatelé, role, přihlášení, CZ/PL,
- auditní základ,
- health check,
- seed prvního `AK_ADMIN` bezpečným CLI příkazem, nikoli pevným heslem v migraci.

Akceptace:

- aplikace se nainstaluje z čistého klonu podle README,
- nepřihlášený uživatel nevidí interní data,
- role jsou kontrolované serverem,
- testovací suite projde,
- v repozitáři není žádné tajemství.

### Fáze 1 – import a doménové jádro

- tabulky orders, customers, items, threads, events, audit, integration checkpoints,
- read-only legacy shop connection,
- import pouze nových uhrazených objednávek od startovního bodu,
- idempotence importu,
- továrna počátečních procesních větví,
- základ dashboardu a detail zakázky,
- zobrazení stáří importu a integračních chyb.

Akceptace:

- opakovaný import nevytvoří duplicitu,
- stará objednávka před startovním bodem se nenačte,
- neúplné mapování skončí viditelnou chybou,
- objednávka může mít více nezávislých větví a fyzických kusů.

### Fáze 2 – základní workflow repase

- stavové automaty a akce zakázkové repase,
- skladová repase z Děčína a z Polska,
- oprávnění obou organizací,
- routing Kraft/Argo,
- produktové ověření,
- mobilní detail a QR,
- audit a časová osa.

Akceptace:

- nelze přeskočit nepovolený stav,
- Polsko nemůže provést akci AUTOKLUBY,
- admin override vyžaduje důvod,
- souběžné odeslání stejné akce nevytvoří dvě události.

### Fáze 3 – zásilky a hromadné skenování

- zásilky, směry, dopravci, tracking,
- Svoz AUTOKLUBY bez falešného trackingu,
- více kusů/zakázek v jedné zásilce,
- hromadné posunutí souvisejících větví,
- příjem zásilky a chyba přepravy.

Akceptace:

- jeden kus nemůže být ve dvou aktivních zásilkách,
- odeslání zásilky a změny větví jsou atomické,
- špatný směr nebo neoprávněný kus je odmítnut.

### Fáze 4 – vratky a finance

- `CORE_RETURN`,
- kontrola AUTOKLUBY a Polska,
- plná/krácená záloha,
- povinný důvod krácení,
- faktury, dobropisy, platby,
- soukromé ukládání dokumentů,
- finanční KPI.

Akceptace:

- vratka není globální stav objednávky,
- krácení bez částky a důvodu nelze potvrdit,
- dokument není přístupný bez autorizace,
- finanční změny jsou auditované.

### Fáze 5 – diskuse a notifikace

- diskuse, přílohy, přečtení/nepřečtení,
- červený badge,
- e-mail při každé nové zprávě,
- fronta, retry, přehled chyb.

Akceptace:

- zpráva nemění workflow stav,
- selhání SMTP nezruší zprávu,
- uživatel bez přístupu k zakázce neotevře zprávu ani přílohu.

### Fáze 6 – reklamace a výkup

- opakovatelné reklamační cykly bez přepisování historie,
- systémová výkupní zakázka,
- polská nabídka, orientačních 70 %, ruční finální cena Pavlem,
- větev výkupu Polskem a její doklady/platby.

Akceptace:

- Reklamace 2 nepřepíše Reklamaci 1,
- nabídka zákazníkovi neodejde bez ručního potvrzení AUTOKLUBY,
- Polsko nikdy neurčí finální zákaznickou cenu.

### Fáze 7 – Pohoda bridge a přecenění

- interní HPSERVER konektor,
- podepsané idempotentní API,
- stock snapshots a heartbeat,
- outbox příkazů,
- přecenění s historií a bezpečnou následnou synchronizací.

Akceptace:

- portál nemá síťový přístup k SQL Pohody,
- opakovaná dávka nevytváří duplicity,
- starý snapshot je v UI označen jako zastaralý,
- cena existující zakázky se zpětně nezmění.

### Fáze 8 – produkční připravenost

- bezpečnostní revize,
- permission test matrix,
- zálohy a test obnovy,
- monitoring a alerty,
- load/smoke test hlavních front,
- provozní manuál,
- školící seed/demo prostředí bez reálných osobních údajů,
- go-live checklist a nastavení importního startovního bodu.

---

## 17. Povinné testy

### Unit testy

- všechny povolené a zakázané stavové přechody,
- odvození „Kdo je na tahu“ a „Další akce“,
- uzavření zakázky až po všech povinných větvích,
- plná a krácená záloha,
- opakované reklamace,
- cenový snapshot.

### Feature testy

- autentizace, rate limit a deaktivovaný účet,
- permission matrix AUTOKLUBY/POLSKO,
- nemožnost přístupu k cizí zakázce přes URL, hledání, QR a soubor,
- idempotentní import e-shopu,
- idempotentní bridge event,
- hromadná zásilka a transakční rollback,
- upload a autorizované stažení,
- diskuse, unread badge a queued e-mail,
- admin override s povinným důvodem.

### Integrační testy

- legacy MySQL adapter nad anonymizovaným fixture datasetem,
- SMTP v testovacím režimu,
- podepisování a ověření HMAC bridge požadavků,
- retry a zpracování částečně chybné dávky.

Testy nesmí používat produkční databázi ani odesílat skutečné e-maily zákazníkům.

---

## 18. Git a způsob práce implementačního agenta

### Repozitář

Použít:

`https://github.com/nightstarter/portal-repaserizeni-cz.git`

- Výchozí větev chránit podle možností GitHubu.
- Nikdy necommitovat `.env`, databázové dumpy, produkční dokumenty, klíče ani logy.
- Přidat `.gitignore` před prvním commitem.
- Používat malé smysluplné commity.
- Tagovat produkční releasy.

### Povinný postup agenta

1. Přečíst celý MASTER v17 a celý tento dokument.
2. Prohlédnout aktuální stav repozitáře a serveru; nepřepisovat existující práci.
3. Zapsat zjištěné verze PHP, MySQL/MariaDB, Composeru a Apache do `docs/DEPLOYMENT.md`.
4. Vytvořit nebo aktualizovat plán fází v README.
5. Implementovat vždy pouze nejbližší nedokončenou fázi.
6. Po každé fázi spustit testy a uvést přesně, co bylo ověřeno.
7. Neprovádět automaticky produkční migraci, mazání dat, změnu DNS, otevření firewallu nebo vytvoření vzdáleného DB účtu bez výslovného schválení provozovatele.
8. Pokud chybí přesné legacy mapování, nejprve vytvořit read-only diagnostický příkaz/report. Nehádat názvy stavů ani význam sloupců.
9. Každé odchýlení od těchto dokumentů popsat v `docs/DECISIONS.md`.
10. Neoznačit fázi za hotovou, pokud neprocházejí její akceptační testy.

### Co agent nesmí udělat

- Kopírovat hardcoded hesla a API klíče ze starého intranetu.
- Zpřístupnit HPSERVER nebo Pohodu internetu.
- Udělit portálu zápisová práva do celé PrestaShop databáze.
- Začít importovat historické objednávky bez startovního bodu.
- Sloučit všechny procesní větve do jednoho `status` sloupce objednávky.
- Povolit klientovi zadat cílový stav bez validované doménové akce.
- Ukládat uploady spustitelně do veřejného webového adresáře.
- Provádět změny přímo v produkci bez commitu a popsaného deploymentu.

---

## 19. Go-live checklist

- [ ] PHP je na podporované cílové verzi.
- [ ] Apache DocumentRoot směřuje pouze do `public/`.
- [ ] HTTPS funguje a HTTP přesměrovává na HTTPS.
- [ ] Produkční debug je vypnutý.
- [ ] Prázdná portálová databáze je zálohovatelná a migrace prošly.
- [ ] Existují pojmenované účty, ne sdílená hesla.
- [ ] Legacy shop účet je read-only, omezený IP a jen na nutné tabulky.
- [ ] `LEGACY_SHOP_IMPORT_FROM`/`MIN_ORDER_ID` je výslovně potvrzený.
- [ ] Import byl ověřen nejprve v dry-run režimu.
- [ ] Pohoda bridge používá nový klíč a pouze odchozí HTTPS.
- [ ] SMTP test neposílá reálným zákazníkům.
- [ ] Privátní dokumenty nelze stáhnout bez přihlášení a oprávnění.
- [ ] Permission matrix prošla testy pro AUTOKLUBY i POLSKO.
- [ ] Cron/scheduler a fronta běží a jsou monitorované.
- [ ] Záloha databáze a souborů je vytvořena a obnova vyzkoušena.
- [ ] Je znám rollback postup.
- [ ] Starý `objednavky.php` zůstává během přechodu interní a beze změny veřejné dostupnosti.

---

## 20. Definice výsledku

Výsledkem není pouze funkční obrazovka. Hotový systém musí současně splnit:

- procesní pravidla MASTER v17,
- oddělené paralelní větve a fyzické kusy,
- bezpečnou práci dvou organizací,
- serverově vynucená oprávnění,
- auditovatelné stavové a finanční změny,
- idempotentní import a integrace,
- nulové příchozí propojení internetu do Pohody,
- mobilní práci přes QR,
- bezpečné dokumenty,
- reprodukovatelné nasazení, testy, zálohu a provozní dohled.

První implementační běh má začít Fází 0. Import produkčních objednávek zůstane vypnutý, dokud nebude dokončena Fáze 1, ověřeno mapování a provozovatel výslovně nepotvrdí startovní datum nebo minimální ID objednávky.
