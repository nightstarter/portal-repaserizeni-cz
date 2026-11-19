# Fáze 6B – uživatelské rozhraní reklamací WARRANTY

## Obrazovky a navigace

- Hlavní menu obsahuje vždy viditelnou položku **Reklamace**.
- `GET /warranties` (`warranties.index`) zobrazuje row-level omezenou frontu WARRANTY větví. Výchozí filtr obsahuje pouze aktivní cykly.
- Detail původní zakázky obsahuje sekci **Reklamace** pro každý fyzický kus.
- Mobilní QR detail aktivního reklamačního cyklu používá stávající autorizovanou route `items.qr` a rozvržení pro šířku 390 px.
- Polský uživatel, který má přístup pouze přes WARRANTY větev, dostane minimalizovaný detail bez původní nepřidělené procesní větve, zákaznických dat, vozidla a VIN.

Fronta zobrazuje původní `order_no`, číslo cyklu, bezpečný identifikátor `items.id`, technický typ, routing, lokalizovaný stav, odpovědnou organizaci, aktivní zásilku dané WARRANTY větve, poslední událost a další doménový krok. Dostupné filtry jsou aktivní, AUTOKLUBY, Polsko, připravené, v přepravě, oprava v Polsku a dokončené.

## Dashboard

Dashboard má čtyři samostatně agregované KPI:

- aktivní reklamace;
- čekající na AUTOKLUBY;
- čekající na Polsko;
- v přepravě.

WARRANTY větve jsou vyřazené z původních workflow KPI a z prezentace globálního stavu objednávky. Agregace i seznamy vždy vycházejí z `ProcessThread::visibleTo()`.

## Detail a historie

Každý kus má samostatný seznam `Reklamace č. N`. Aktivní cyklus je vizuálně zvýrazněný, dokončené cykly jsou pouze pro čtení. Každý cyklus má vlastní časovou osu a vlastní zásilky; události různých cyklů se neslučují.

Interní důvod založení je uložen v `thread_events.reason` a auditu. Zobrazuje se pouze AUTOKLUBY. Polské projekce důvod ani jiné interní metadata nepřenášejí. Blade automaticky escapuje text poznámek proti XSS.

## Založení a concurrency

Formulář založení je dostupný pouze `AK_OPERATOR` a `AK_ADMIN`, když kus nemá aktivní reklamaci ani aktivní zásilku. Vyžaduje:

- CSRF token;
- idempotency key;
- `expected_lock_version` fyzického kusu;
- neprázdný interní důvod do 2 000 znaků.

Stále pending migrace 000012 byla před nasazením doplněna o `items.warranty_lock_version`. Založení a terminální uvolnění aktivní reklamace tuto verzi atomicky zvyšují. Stale formulář skončí bez zápisu a zobrazí lokalizovaný aktuální stav.

## Akce a zásilky

UI nikdy nenabízí volný výběr stavu. Zobrazuje pouze akce odvozené z aktuálního stavu a role:

- AUTOKLUBY: příprava pro Polsko a terminální předání zákazníkovi;
- Polsko: dokončení opravy a příprava pro AUTOKLUBY;
- READ_ONLY: žádná zapisovací akce;
- AK_ADMIN: stejné běžné akce jako AK_OPERATOR; auditovaný override zůstává doménově dostupný, ale není standardním UI ovládacím prvkem.

Akce odeslání a příjmu mezi organizacemi nejsou přijímány WARRANTY HTTP command routou. UI odkazuje na existující obrazovky zásilek a jedinou zapisovací cestou zůstává `ShipmentService`. Konečné odeslání zákazníkovi nebo osobní odběr vyžaduje potvrzení a nevytváří tracking.

## Lokalizace a mobilní provoz

České a polské překlady pokrývají menu, KPI, filtry, všechny WARRANTY stavy a akce, historii, prázdné stavy, validaci a concurrency chyby. Interní stavové kódy se v HTML nezobrazují. Dlouhé poznámky a odkazy se zalamují; dotykové akce mají mobilní výšku a při 390 px nepřetékají.

## Výkon

Fronta používá eager loading, stránkování po 100 větvích a deterministické řazení podle poslední události a ID. Dashboard KPI používá jeden agregovaný dotaz. Regresní měření pro 100 aktivních větví a deset kusů se dvěma cykly:

- dashboard včetně menu: 17 SELECT dotazů;
- fronta reklamací: 11 SELECT dotazů;
- detail zakázky s více kusy a historickými cykly: 19 SELECT dotazů.

Počet dotazů je konstantní vůči počtu záznamů; test povoluje nejvýše rozdíl jednoho dotazu mezi malou a velkou sadou.

## Testovací a provozní bezpečnost

Testy používají výhradně `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` a stávající fail-fast guard. Produkční migrace 000012 nebyla spuštěna. Produkční DB, `.env`, cron, private storage, e-mailové doručování ani legacy import nebyly změněny.

## Známá omezení

- Fronta stránkuje po 100 cyklech; samostatné průběžné načítání dalších stránek používá standardní Laravel pagination.
- Přiřazení Polska zůstává v souladu s existujícím modelem na úrovni organizace, nikoli individuálního uživatele.
- Finální cross-browser a dotykové ověření na fyzickém zařízení není součástí automatické fáze 6B.
- Admin override zůstává záměrně mimo běžné WARRANTY UI; používá pouze existující auditovaná pravidla doménové vrstvy.
