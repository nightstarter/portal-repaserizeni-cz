# Fáze 6D – uživatelské rozhraní výkupu

## Rozsah

Fáze 6D doplňuje nad doménovým jádrem 6C autorizovanou frontu, založení a detail výkupu, KPI, cenové formuláře, dokončení zákaznické nabídky, zpracování jejího outboxu a rozšíření stávající administrace notifikací. Stavový automat, append-only cenová historie, `ShipmentService`, privátní dokumenty a ledger zůstaly jedinými autoritativními mechanismy.

Fáze nepřidává migraci. Produkční migrace `000012` a `000013` nebyly spuštěny.

## Routy a obrazovky

- `GET /buybacks` (`buybacks.index`) – row-level fronta, výchozí aktivní výkupy a jedenáct filtrů;
- `GET /buybacks/create` (`buybacks.create`) – formulář pouze pro `AK_ADMIN` a `AK_OPERATOR`;
- `POST /buybacks` (`buybacks.store`) – idempotentní založení a přesný převod hlavní měnové jednotky na minor units;
- `GET /orders/{order}` (`orders.show`) – pro `BUYOUT` vykreslí specializovaný detail;
- stávající command, zásilkové, dokumentové a download routy se používají bez paralelních zkratek.

Hlavní menu obsahuje položku **Výkup** s aktivním stavem. Fronta ukazuje systémové číslo, workflow, kus a technický typ, odpovědnost, poslední událost, další akci, zásilku a doklady/finance. Stav zákaznické nabídky je pouze pro AUTOKLUBY. Dashboard má oddělená row-level KPI pro aktivní výkupy, Polsko, finální cenu, zákazníka, kus a finance.

Detail obsahuje „Co mám udělat teď“, pouze autorizované akce aktuálního stavu, zákaznické údaje jen pro AUTOKLUBY, fyzický kus, cenové historie, 70% doporučení jen pro AUTOKLUBY, bezpečnou časovou osu, existující zásilky, privátní doklady, ledger a odkaz do společné diskuse. Rozvržení, zalamování a dotykové akce jsou upraveny pro 390 px.

## Role a bezpečnost

- `AK_OPERATOR`: založení a provozní kroky, žádost o polskou nabídku, přijetí/kontrola kusu a existující zásilkové UI;
- `AK_ADMIN`: navíc ruční finální cena, potvrzení a zařazení zákaznické nabídky, platba a správa retry;
- Polsko: jen row-level dostupné výkupy, vlastní nabídky a polské provozní kroky;
- `READ_ONLY`: autorizované čtení bez doménových a dokumentových formulářů.

Formuláře používají CSRF, idempotency key, `lock_version` a klientské zabránění dvojímu submitu; backend zůstává rozhodující autoritou. Peníze parsuje `MinorMoney` z textu bez float a bez konverze měn. Blade escapuje poznámky i názvy. Polský detail ani fronta nenačítají/neukazují zákaznickou identitu, kontakt, interní nabídku, finální cenu, 70% doporučení ani stav zákaznické nabídky. Cizí přímá URL zůstává bezpečná 404.

## Zákaznický e-mail a administrace

Stávající `php artisan notifications:dispatch --limit=50` nyní zpracuje také `BUYBACK_CUSTOMER_OFFER_EMAIL`. Nevznikl druhý worker ani cron. Používá stejné atomické claimy, lock token, ochranu souběhu, obnovu opuštěného locku, exponenciální retry, limit pokusů, sanitizaci chyb a audit jako diskusní notifikace.

Před odesláním znovu ověří stav `BUYBACK_CUSTOMER_OFFER_READY`, ručně schválenou finální cenu, oprávnění schvalujícího a validní zákaznický e-mail. E-mail je česky, obsahuje pouze systémové číslo, finální cenu/měnu a kontaktní instrukci. Neobsahuje polskou nabídku, interní poznámky, přílohy, token ani veřejný potvrzovací odkaz.

Při `BUYBACK_CUSTOMER_EMAIL_ENABLED=false` se typ neclaimuje, SMTP se nekontaktuje, pending event zůstane zachován a workflow zůstává v `CUSTOMER_OFFER_READY`. Stav `OFFER_SENT` spolu se systémovou auditní událostí vznikne atomicky s označením outboxu jako `sent` až poté, co mail transport úspěšně přijme zprávu. `/admin/notifications` rozlišuje oba typy, maskuje příjemce, ukazuje oddělený feature-flag stav a dovolí auditovaný retry pouze `AK_ADMIN`; retry výkupu neobejde vypnutý flag ani chybějící schválení.

## Výkup Polskem, doklady a finance

Přeprava do Polska se zahajuje pouze z existujícího zásilkového UI a `ShipmentService`. Detail nepřidává bypass tlačítko. Po přijetí Polskem AUTOKLUBY nahraje fakturu na privátní dokumentový disk, může ji nahradit pouze novou auditovanou verzí a autorizovaně stáhnout. Platbu potvrdí jen `BUYBACK_PAYMENT_CONFIRM`; výsledkem je append-only ledger. Větev `RETAINED_AK` fakturu ani polskou platbu nenabízí.

## Testy a výkon

Testy pokrývají UI, role, filtry/prázdný stav, přesné minor units, XSS, CZ/PL lokalizaci, polskou PII izolaci, stale/idempotentní doménu, oba výsledky výkupu, zásilky, privátní faktury/verzování/download, ledger, feature flag, Mail fake, bezpečný obsah e-mailu, administraci, maskování a retry.

Regresní query-count test porovnává 2 a 100 výkupů a malý/bohatý detail s 30 cenami, zásilkami, doklady a ledger záznamy. Po odstranění nalezeného N+1 zůstávají počty konstantní; limity jsou dashboard/menu ≤ 28 SELECT, fronta ≤ 18 SELECT a detail ≤ 22 SELECT.

Všechny testy musí projít fail-fast guardem s `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Mailer je fake/array a feature flagy jsou ve výchozím stavu vypnuté.

Finální izolovaný `php8.5 composer.phar check` prošel: **396 testů, 1 891 assertions**. Zahrnul Composer validaci, Pint, syntaktickou kontrolu a kompletní testovací sadu.

## Nasazení a provozní nastavení

Před nasazením doménového jádra 6C/6D je nutné standardně zálohovat DB a private storage a aplikovat dosud pending migrace `2026_11_19_000012_add_phase_6a_warranty_threads.php` a `2026_11_19_000013_create_phase_6c_buybacks.php`. Fáze 6D samotná schéma nemění.

Produkční `.env` nejprve ponechat:

```dotenv
DISCUSSION_EMAIL_ENABLED=false
BUYBACK_CUSTOMER_EMAIL_ENABLED=false
LEGACY_SHOP_IMPORT_ENABLED=false
POHODA_BRIDGE_ENABLED=false
```

Po kontrolovaném ověření SMTP, administrace a pending eventů lze samostatně nastavit `BUYBACK_CUSTOMER_EMAIL_ENABLED=true`, rebuildnout config cache a ověřit jediný stávající scheduler/worker. Produkční `.env`, DB, cron a private storage nebyly během implementace změněny.

## Známá omezení

- Zákazník nemá veřejný portál ani potvrzovací URL; přijetí zapisuje AUTOKLUBY ruční auditovanou akcí.
- E-mailové doručení je at-least-once vůči externímu SMTP; transakční outbox nemůže garantovat exactly-once po přerušení mezi přijetím SMTP a lokálním `sent_at`.
- UI individuální správy oprávnění není součástí 6D; výchozí mapování zůstává role-based.
- Automatická konverze měn se záměrně neprovádí. Doporučených 70 % zachovává měnu poslední polské nabídky a Polsku se nezobrazuje.
- Ručné browser testy desktopu a 390 × 844 nebyly v této implementační relaci provedeny nad produkčními daty.
