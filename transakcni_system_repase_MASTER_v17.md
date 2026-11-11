# Transakční systém repasovaných řízení – MASTER SPEC v17

## 1. Cíl systému

Systém nahrazuje WhatsApp, Excelové tabulky a část e-mailové komunikace jedním transakčním systémem mezi dvěma organizacemi:

- **AUTOKLUBY** – administrátor celého systému, plná práva.
- **POLSKO** – druhá vstupní brána, upravuje pouze data, stavy, finance a dokumenty, za které reálně odpovídá.

Systém musí vždy odpovědět na pět otázek:

1. O jakou zakázku jde?
2. Kde jsou jednotlivá fyzická řízení?
3. Kdo je právě na tahu?
4. Co je další povolená akce?
5. Jaký je stav dokumentů a peněz?

Hlavní identifikace zakázky je **číslo objednávky**. QR kód je fyzická zkratka do detailu zakázky.

---

## 2. Dvě brány a jazyky

### AUTOKLUBY
- vlastní a administruje celý systém,
- vidí všechny zakázky a všechny větve,
- může opravovat data a provádět administrátorský override s auditním záznamem,
- spravuje zákaznickou část, expedici zákazníkovi, příjem od zákazníka, finální výkupní cenu, reklamace a potvrzení účetních plateb.

### POLSKO
Vidí pouze zakázky a části zakázek, ve kterých má úkol nebo oprávněný přístup. Může:
- potvrdit dostupnost skladového kusu,
- zadat předpokládaný termín,
- potvrdit příjem řízení,
- označit řízení jako opravené,
- vytvořit zásilku zpět do AUTOKLUBY,
- vložit polskou fakturu, nákupní cenu a zálohu,
- zkontrolovat vratku,
- určit plnou / krácenou vracenou zálohu a důvod krácení,
- vložit dobropis a potvrdit jeho vyplacení,
- zadat výkupní cenu,
- provést přecenění produktu,
- komunikovat v diskusi.

Nemůže měnit zákaznická data, finální cenu zákazníkovi, stav skladu Děčín, produktové přednastavení ani interní stavy AUTOKLUBY.

### Jazyk
- horní přepínač **CZ / PL**,
- AUTOKLUBY defaultně čeština,
- Polsko defaultně polština,
- preference se ukládá k uživateli,
- všechny stavy, tlačítka, formuláře a systémové e-maily se překládají podle jazykového slovníku,
- předdefinované závady mají český i polský název,
- volný text v diskusi se automaticky nepřekládá.

---

## 3. Nejdůležitější architektonické rozhodnutí

### Zakázka není jeden lineární stav

U jedné skladové objednávky z Děčína mohou zároveň probíhat:

1. expedice skladového kusu zákazníkovi,
2. doplnění skladu z Polska,
3. čekání na vratný díl zákazníka,
4. přeprava vratky do Polska,
5. polská kontrola vratky a vrácení zálohy.

Proto má zakázka **paralelní procesní větve (threads)**. Každá větev má vlastní stav a odpovědnou stranu.

Uživatel však nepracuje s technickou strukturou. V detailu vidí jednoduché karty:
- Zákazník,
- Skladový kus / oprava,
- Vratka,
- Finance,
- případně Reklamace / Výkup.

Hlavní seznam zakázek zobrazuje **Další akci** a **Kdo je na tahu**, ne jeden přetížený univerzální stav.

---

## 4. Identifikace: číslo objednávky, QR a fyzické kusy

### Viditelná identifikace
- `order_no` = číslo objednávky / hlavní ID zakázky,
- QR kód vždy vede na detail této zakázky,
- jedna zákaznická objednávka obsahuje vždy jedno objednané řízení.

### Důležitá nekonzistence a její řešení
U skladové objednávky mohou být pod jedním `order_no` současně:
- repasované řízení vydané zákazníkovi,
- staré řízení vracené zákazníkem,
- nový náhradní skladový kus posílaný z Polska do Děčína.

Proto systém musí mít **interní ID fyzického kusu** (`item_id`). Uživatel ho nemusí běžně vidět.

QR může být:
- základní: `/order/12345`
- na konkrétním fyzickém štítku: `/order/12345?item=abc`

Po načtení se vždy otevře stejná zakázka, ale systém ví, se kterým fyzickým kusem pracovník právě pracuje.

---

## 5. Základní datové objekty

### Order / Zakázka
Obsahuje:
- číslo objednávky,
- zdroj (e-shop / systémový výkup),
- zákazníka,
- vozidlo, VIN,
- kód zboží,
- typ zakázky,
- technický typ: **šnek / hydraulika / EPS**,
- routing: **Kraft / Argo**,
- způsob zákaznického předání: GLS / osobní odběr,
- stav ověření produktu,
- vazby na všechny procesní větve.

### Item / Fyzický kus
- interní ID,
- order_no,
- role kusu: skladový kus / zákaznický kus / vratka / náhradní skladový kus / reklamovaný kus / výkup,
- QR token,
- aktuální lokalita,
- kontrolní výsledek,
- chybový popis.

### Process Thread / Procesní větev
Typy:
- `STOCK_REQUEST`
- `CUSTOM_REBUILD`
- `CORE_RETURN`
- `STOCK_REPLENISHMENT`
- `WARRANTY`
- `BUYOUT`

Každá větev má:
- stav,
- odpovědnou stranu,
- ETA / termín,
- datum poslední změny,
- další povolené akce.

### Shipment / Přeprava
- směr,
- dopravce / způsob,
- tracking nebo Svoz AUTOKLUBY,
- stav,
- datum odeslání / přijetí,
- seznam fyzických kusů.

Jedna zásilka AUTOKLUBY ↔ Polsko může obsahovat více zakázek.

### Discussion / Diskuse
- vždy navázaná na zakázku,
- chronologická,
- společná AUTOKLUBY ↔ Polsko,
- autor, čas, text, přílohy,
- nepřečteno/přečteno.

### Documents / Doklady
- polská faktura,
- dobropis Polska,
- faktura AUTOKLUBY → Polsko u výkupu,
- případné další doklady,
- soubor, číslo, datum, částka, měna, stav úhrady.

### Finance
- nákupní cena Polska,
- původní záloha,
- finální vracená záloha,
- důvod krácení,
- stav dobropisu,
- výkupní cena Polska,
- finální cena zákazníkovi,
- stav platby Polska AUTOKLUBY.

---

## 6. Co je stav a co není stav

### Stav JE
Událost, která říká, kde proces skutečně je a kdo je na tahu:
- Čeká na řízení zákazníka
- Odesláno do Polska
- Přijaté k repasi
- Opravené v Polsku
- Odeslané k AUTOKLUBY
- Čeká na skladový kus z Polska
- Polsko zajišťuje skladový kus
- Čeká na vratný díl
- Vratka v kontrole
- Dobropis vystaven
- Odesláno zákazníkovi
- Připraveno k osobnímu odběru

### Stav NENÍ
Tyto věci mají být atributy / kontrolní výsledky:
- Kraft / Argo,
- šnek / hydraulika / EPS,
- Dorazilo správné / jiné řízení,
- axiální táhla,
- chybový text,
- kód zboží,
- přítomnost zálohy,
- výše ceny,
- přepravce.

To zabraňuje explozivnímu množství stavů.

---

## 7. Workflow: zakázková repase

1. Uhrazená objednávka je importována z e-shopu.
2. Typ = zakázková repase.
3. Čeká na řízení zákazníka.
4. Zákazník posílá Zásilkovnou; 1 objednávka = 1 balík = 1 tracking.
5. Pavel / sklad načte QR a potvrdí příjem.
6. Kontrola:
   - `Dorazilo správné řízení` default,
   - nebo `Dorazilo jiné řízení`,
   - `Chybový stav řízení` textarea,
   - axiální táhla => 700 Kč,
   - potvrzení Kraft/Argo,
   - závada:
     - Únik oleje
     - Klepe
     - Vadná jednotka
     - Stav neznámý
     - + textarea.
7. Připraví se štítek s QR, jménem a order_no.
8. AUTOKLUBY odešle do Polska přes GLS nebo Svoz AUTOKLUBY.
9. Polsko:
   - Přijaté řízení k repasi
   - Opravené řízení na straně Polska
   - Odeslané k AUTOKLUBY
10. Všechny problémy / změny ceny / technické dotazy se řeší diskusí, nikoli dalšími stavy.
11. AUTOKLUBY načte QR, zkontroluje faktickou shodu a stav zásilky.
12. Buď:
   - vytvoří GLS → `Odesláno zákazníkovi` = konec,
   - nebo `Připraveno k osobnímu odběru` → `Převzato zákazníkem` = konec.

---

## 8. Workflow: skladová repase – kus je v Děčíně

Pohoda je autoritativní zdroj skladové dostupnosti.

Po objednávce běží paralelně tři větve:

### A. Zákaznické plnění
- kus ze skladu Děčín → zákazník,
- `Odesláno zákazníkovi` = tato větev dokončena.

### B. Doplnění skladu
- objednávka současně vyvolá požadavek Polsku na náhradní skladový kus,
- pokud Polsko má: připraví a odešle,
- pokud nemá: `Polsko zajišťuje skladový kus` + předpokládaný termín,
- Polsko → DPD / Svoz AUTOKLUBY → Děčín,
- přijetí QR → doplnění skladu dokončeno.

### C. Vratný díl
- `Čeká na vratný díl`,
- zákazník vrátí původní řízení,
- AUTOKLUBY zkontroluje,
- AUTOKLUBY → Polsko,
- Polsko přijme a zkontroluje,
- plná / krácená záloha,
- dobropis,
- vyplacení zálohy.

Zakázka je kompletně uzavřena, až jsou dokončeny všechny povinné větve.

---

## 9. Workflow: skladová repase – kus je v Polsku

1. Uhrazená objednávka.
2. Pohoda říká, že Děčín kus nemá.
3. `Čeká na skladový kus z Polska`.
4. Polsko odpoví:
   - Skladem,
   - nebo `Polsko zajišťuje skladový kus` + ETA.
5. Polsko vloží fakturu, nákupní cenu a případnou zálohu.
6. DPD / Svoz AUTOKLUBY → AUTOKLUBY.
7. QR příjem.
8. AUTOKLUBY → zákazník.
9. Paralelně vzniká vratková větev:
   - Čeká na vratný díl
   - Přijat AUTOKLUBY
   - Odeslán Polsku
   - Přijat Polskem
   - Kontrola
   - plná / krácená záloha
   - dobropis
   - vyplaceno.

---

## 10. Workflow: vratný díl

Kontrola v AUTOKLUBY:
- dohledání podle čísla objednávky,
- správný kus,
- stav po přepravě,
- nehoda,
- rozebrání,
- koroze,
- axiální táhla.

Polsko:
1. Přijme vratku.
2. Zkontroluje.
3. Vybere:
   - plná záloha,
   - krácená záloha.
4. Při krácení povinně:
   - finální vracená částka,
   - důvod.
5. Nahraje dobropis.
6. Označí vyplacení zálohy.

Filtr **Vratky** zobrazuje všechny otevřené `CORE_RETURN` větve, ne objednávky s jedním globálním stavem „Vratka“.

---

## 11. Workflow: reklamace

- reklamace zůstává pod původním order_no,
- nevzniká nová objednávka,
- každá reklamace je samostatný cyklus:
  - Reklamace 1
  - Reklamace 2
  - …
- historie předchozích reklamací se nikdy nepřepisuje.

Průběh je stejný jako zakázková repase, ale větev je označena `WARRANTY` / Reklamace:
- přijato AUTOKLUBY,
- odesláno Polsku,
- přijato k reklamaci,
- opraveno,
- odesláno AUTOKLUBY,
- přijato,
- odesláno zákazníkovi / osobní odběr.

---

## 12. Workflow: výkup

1. Zákazník nabídne řízení k výkupu.
2. Vznikne systémová zakázka / order_no.
3. AUTOKLUBY může určit vlastní cenu.
4. Polsko vidí nabídku a může zadat svoji výkupní cenu.
5. Systém zobrazí orientačních **70 % polské ceny**.
6. Pavel vždy ručně stanoví finální cenu zákazníkovi.
7. Nabídka odejde zákazníkovi až po potvrzení Pavlem.
8. Pokud zákazník přijme, pošle řízení.
9. AUTOKLUBY přijme a zkontroluje kus.
10. Pokud Pavel zaškrtne `Vykupuje Polsko`:
    - Připraveno pro Polsko
    - Odesláno do Polska
    - Přijato Polskem
    - AUTOKLUBY nahraje fakturu Polsku
    - Čeká na platbu
    - AUTOKLUBY podle účetnictví označí `Vyplaceno Polskem`.
11. Pokud checkbox není zaškrtnut, kus zůstává AUTOKLUBY.

---

## 13. Routing Kraft / Argo

Výchozí routing:
- šnek → Kraft
- hydraulika → Argo
- EPS → Argo

Je přenesen z produktu do objednávky.
Pavel jej může změnit na konkrétní objednávce.
Změna objednávky nikdy nemění produktové přednastavení.

---

## 14. Ověření produktu a záměny

Nezavádí se stav „Čeká na identifikaci“.

Existuje potvrzení:
- `Identifikováno jako správně vybrané`.

Pokud ověření VIN / katalogu ukáže jiný produkt:
- AUTOKLUBY může změnit kód zboží na konkrétní objednávce.

Důležité rozlišení:
- **špatně identifikovaný produkt před odesláním** = změna kódu produktu,
- **fyzicky dorazil jiný kus** = kontrolní výsledek `Dorazilo jiné řízení` + chyba / diskuse.

---

## 15. Přeprava

### Typy
- Zákazník → AUTOKLUBY: Zásilkovna, vždy 1 order / 1 balík.
- AUTOKLUBY → Polsko: GLS nebo Svoz AUTOKLUBY, více zakázek v jednom balíku / svozu.
- Polsko → AUTOKLUBY: DPD nebo Svoz AUTOKLUBY, více zakázek.
- AUTOKLUBY → zákazník: GLS, vždy 1 objednávka / 1 řízení.

### Svoz AUTOKLUBY
- Petr Fišer jede 1× týdně dodávkou do Polska,
- odváží nahromaděné vratky / řízení,
- přiváží hotové repase,
- `Svoz AUTOKLUBY` je **typ přepravy**, nikoli falešné tracking číslo.

### Shipment status
- Připraveno
- Odesláno
- Doručeno
- Chyba přepravy

Při `Chyba přepravy` se zobrazí textarea a přílohy.

---

## 16. Hromadná práce se zásilkami – důležité UX

### Odesílání více řízení do Polska
1. pracovník zvolí `Nová zásilka`,
2. vybere GLS / Svoz AUTOKLUBY,
3. skenuje QR jednotlivých řízení,
4. seznam se plní,
5. u GLS zadá tracking jednou,
6. klikne `Odeslat`,
7. všechny příslušné procesní větve se současně posunou na `Odesláno do Polska`.

Stejný princip používá Polsko pro DPD / Svoz AUTOKLUBY.

Tím se odstraní ruční zapisování stejného trackingu do několika řádků.

---

## 17. Dokumenty a finance

### Polsko → AUTOKLUBY
Každá obchodní zakázka má:
- polskou fakturu jako soubor,
- nákupní cenu,
- případnou zálohu.

### Vratka
- původní záloha,
- plná / krácená,
- finální vracená částka,
- důvod krácení,
- dobropis jako soubor,
- stav `vystaven / vyplacen`.

### Výkup pro Polsko
- AUTOKLUBY vloží fakturu,
- Polsko ji uhradí,
- AUTOKLUBY podle účetního systému označí `Uhrazeno`.

Všechny dokumenty jsou viditelné v záložce **Doklady & finance** konkrétní zakázky.

---

## 18. Přecenění

Samostatná záložka pro Polsko.

Polsko zadá:
- kód zboží,
- novou **nákupní cenu**.

Systém uchová:
- původní cenu,
- novou cenu,
- datum/čas,
- uživatele.

Změna se propíše okamžitě.
Technickou synchronizaci do e-shopu / cenotvorby řeší programátor.

Nová cena platí pouze pro nové objednávky.
Existující objednávky si ponechávají původní cenu.

---

## 19. Diskuse a notifikace

Každá zakázka má jednu společnou diskusi AUTOKLUBY ↔ Polsko.

Použití:
- technické dotazy,
- komplikace,
- změny ceny během opravy,
- nejasné VIN,
- individuální řešení poškození,
- jakákoli výjimka, která nestojí za nový stav.

### Notifikace
- nahoře červená ikona s počtem nepřečtených zpráv,
- při **každé nové zprávě** e-mailová notifikace,
- klik vede rovnou na zakázku a diskusi.

Diskuse nikdy nemění stav automaticky.

---

## 20. UX: hlavní princip

Uživatel nesmí vybírat stav z dvaceti položek.

Stav se mění provedením **akce**:

- `Potvrdit přijetí`
- `Odeslat do Polska`
- `Přijato k repasi`
- `Opraveno`
- `Odeslat k AUTOKLUBY`
- `Přijmout vratku`
- `Schválit plnou zálohu`
- `Schválit krácenou zálohu`
- `Nahrát fakturu`
- `Odeslat zákazníkovi`
- `Připraveno k osobnímu odběru`

Akce provede validaci povinných polí a teprve potom posune stav.

---

## 21. Dashboard AUTOKLUBY

Horní KPI / filtry:
- Čeká na nás
- Čeká na Polsko
- V přepravě
- Vratky
- Reklamace
- Výkupy
- Finance k řešení
- Chyby
- Nepřečtené zprávy

Hlavní tabulka:
- order_no
- vozidlo
- typ zakázky
- aktivní větve / stavové čipy
- kdo je na tahu
- další akce
- ETA
- zprávy
- finanční upozornění

Vyhledávání:
- číslo objednávky
- VIN
- kód zboží
- tracking
- zákazník

---

## 22. Dashboard Polska

Polsko nevidí interní chaos AUTOKLUBY. Vidí pouze vlastní fronty:

- Nové požadavky na skladový kus
- Polsko zajišťuje skladový kus
- Řízení k repasi
- Opravené – čeká na expedici
- Vratky k přijetí / kontrole
- Výkupní nabídky
- Faktury / dobropisy k doplnění
- Přecenění
- Nepřečtené zprávy

Každá řádka má jedno hlavní tlačítko další akce.

---

## 23. Detail zakázky – desktop

### Header
- #order_no
- QR
- vozidlo
- kód produktu
- šnek/hydraulika/EPS
- Kraft/Argo
- typ zakázky
- aktuálně: **Kdo je na tahu + Další akce**

### Procesní karty
Např. skladovka Děčín:
- Zákazník ✅ Odesláno
- Doplnění skladu ⏳ Polsko zajišťuje, ETA 25. 10.
- Vratka ⏳ Čeká na zákazníka
- Finance ⏳ Záloha 4 000 Kč

### Tabs
- Přehled
- Diskuse
- Přepravy
- Doklady & finance
- Historie

---

## 24. Detail zakázky – mobil po QR

Po načtení QR:
1. krátký header zakázky,
2. jedna velká karta **„Co mám udělat teď“**,
3. 1–3 velká tlačítka,
4. sekundárně Diskuse / Doklady / Historie.

Příklad příjmu zákazníkova řízení:

**#123456 – VW Passat – EPS – Argo**

`Očekáváme řízení od zákazníka`

- [ POTVRDIT PŘIJETÍ ]
- Správné řízení ✓
- Jiné řízení
- Axiální táhla
- Chyba / poznámka

Poté:

- [ VYTISKNOUT QR ŠTÍTEK ]
- [ PŘIDAT DO ZÁSILKY DO POLSKA ]

Cíl: skladník nemusí otevírat tabulku ani přemýšlet, jaký stav ručně vybrat.

---

## 25. Oprávnění – stručná matice

| Akce | AUTOKLUBY | POLSKO |
|---|---|---|
| Vidět všechny zakázky | ano | pouze relevantní |
| Editovat zákazníka | ano | ne |
| Změnit kód produktu | ano | ne |
| Potvrdit Kraft/Argo | ano | pouze vidí |
| Potvrdit příjem v AUTOKLUBY | ano | ne |
| Potvrdit příjem v Polsku | admin override | ano |
| Opraveno v Polsku | admin override | ano |
| Odeslat z Polska | admin override | ano |
| Odeslat zákazníkovi | ano | ne |
| Polská faktura/cena/záloha | vidí/admin | ano |
| Kontrola vratky Polskem | vidí/admin | ano |
| Finální výkupní cena zákazníkovi | ano | ne |
| Výkupní nabídka Polska | vidí | ano |
| Přecenění nákupní ceny | vidí/admin | ano |
| Diskuse | ano | ano |
| Administrátorský override | ano | ne |

Každý override se zapisuje do auditu.

---

## 26. Stavový slovník – příklady CZ / PL

| Kód | CZ | PL |
|---|---|---|
| WAITING_CUSTOMER_ITEM | Čeká na řízení od zákazníka | Oczekuje na przekładnię od klienta |
| RECEIVED_AK | Přijaté AUTOKLUBY | Przyjęte w AUTOKLUBY |
| SENT_TO_PL | Odeslané do Polska | Wysłane do Polski |
| RECEIVED_PL_REPAIR | Přijaté k repasi | Przyjęte do regeneracji |
| REPAIRED_PL | Opravené v Polsku | Zregenerowane w Polsce |
| SENT_TO_AK | Odeslané k AUTOKLUBY | Wysłane do AUTOKLUBY |
| WAITING_PL_STOCK | Čeká na skladový kus z Polska | Oczekuje na część magazynową z Polski |
| PL_SECURING_STOCK | Polsko zajišťuje skladový kus | Polska organizuje część magazynową |
| WAITING_CORE | Čeká na vratný díl | Oczekuje na zwrot starej przekładni |
| CORE_INSPECTION | Vratka v kontrole | Zwrot w kontroli |
| CREDIT_ISSUED | Dobropis vystaven | Korekta wystawiona |
| CREDIT_PAID | Dobropis proplacen | Korekta rozliczona |
| SENT_CUSTOMER | Odesláno zákazníkovi | Wysłane do klienta |
| READY_PICKUP | Připraveno k osobnímu odběru | Gotowe do odbioru osobistego |
| PICKED_UP | Převzato zákazníkem | Odebrane przez klienta |

V databázi se ukládá pouze stabilní kód. Text se překládá podle jazyka.

---

## 27. Integrace

### E-shop
Importuje zejména:
- číslo objednávky,
- úhradu,
- zákazníka,
- vozidlo / VIN,
- kód produktu,
- typ zakázky,
- technický typ,
- produktové přednastavení Kraft/Argo.

### Pohoda
- autoritativní informace o fyzické skladové dostupnosti v Děčíně,
- přesný směr synchronizace dořeší programátor.

### Dopravci
- GLS / DPD / Zásilkovna,
- pokud API umožní, systém automaticky aktualizuje pohyb a doručení,
- bez API lze potvrdit ručně.

### E-mail
- notifikace každé nové zprávy,
- případně systémová upozornění.

---

## 28. Audit / historie

Každá důležitá změna musí mít:
- kdo,
- kdy,
- stará hodnota,
- nová hodnota,
- zakázka,
- případně důvod.

Auditovat zejména:
- stavy,
- kód zboží,
- Kraft/Argo,
- ceny,
- zálohy,
- krácení,
- dokumenty,
- výkupní ceny,
- ruční override.

Uživatel vidí v zakázce jednoduchou **časovou osu**, administrátor plný audit.

---

## 29. Nekonzistence nalezené v původním návrhu a jejich řešení

1. **Jeden stav objednávky nestačí.**
   Řešení: paralelní procesní větve + odvozená „Další akce“.

2. **Jedno order_no může obsahovat více fyzických kusů v různých směrech.**
   Řešení: interní `item_id`; QR pořád vede na zakázku.

3. **Tracking byl používán jako vlastnost objednávky.**
   Řešení: samostatná zásilka; jeden tracking může obsahovat více objednávek.

4. **Svoz AUTOKLUBY nemá být falešný tracking.**
   Řešení: samostatný typ přepravy.

5. **Vratka není stav celé objednávky.**
   Řešení: samostatná `CORE_RETURN` větev; filtr Vratky pracuje nad ní.

6. **Reklamace přepisující původní objednávku by ztratila historii.**
   Řešení: reklamace je cyklus uvnitř stejné objednávky.

7. **Dorazilo jiné řízení a změna kódu produktu jsou dvě různé situace.**
   Řešení: oddělit identifikaci produktu od fyzické kontroly příjmu.

8. **Technické problémy byly kandidáti na desítky stavů.**
   Řešení: pouze 3 stavy polské opravy; výjimky do diskuse.

9. **Finance byly rozptýlené po tabulkách.**
   Řešení: doklady a finance přímo pod zakázkou a konkrétní větví.

10. **Historické cenové listy nejsou workflow.**
    Řešení: samostatná funkce Přecenění.

11. **Polská strana nesmí měnit data AUTOKLUBY.**
    Řešení: organizační permissions matrix.

12. **QR jen s order_no by při paralelních fyzických kusech mohl být nejednoznačný.**
    Řešení: QR otevírá order, volitelně současně nese interní `item_id`.

---

## 30. Co musí být MVP

1. Dvě brány + oprávnění.
2. CZ / PL.
3. Import objednávek z e-shopu.
4. Dostupnost skladu z Pohody.
5. Zakázky + paralelní procesní větve.
6. QR + mobilní detail.
7. Zásilky a hromadné skenování.
8. Zakázková repase.
9. Skladovka Děčín / Polsko.
10. Vratky a finanční vypořádání.
11. Reklamace.
12. Výkup.
13. Diskuse + červený badge + e-mail při každé zprávě.
14. Doklady / faktury / dobropisy.
15. Přecenění.
16. Filtry a vyhledávání.
17. Audit.

---

## 31. Co nepatří do MVP / nechává se programátorovi

- konkrétní technologie backendu/frontendu,
- způsob synchronizace Pohody,
- carrier webhooky vs polling,
- tiskový protokol QR tiskárny,
- způsob autentizace,
- databázová technologie,
- případná automatická překladová služba pro volný text,
- přesná vazba nákupní ceny na zákaznickou cenotvorbu e-shopu.

---

## 32. Jedna věta pro vývojáře

**Zakázka je master záznam s order_no; pod ní běží několik nezávislých procesních větví, fyzických kusů, zásilek, dokumentů a finančních událostí. Uživatel nikdy nemění abstraktní stav ručně – provádí povolenou akci, která stav bezpečně posune, a QR ho vždy dostane přímo k dané zakázce a fyzickému kusu.**