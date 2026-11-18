# Fáze 4D — navigace Vratky a Doklady a finance

Fáze 4D odstraňuje závislost operativy vratek a financí na dynamických kartách dashboardu a na znalosti konkrétní zakázky. Nemění databázové schéma, stavové automaty, ledger ani document storage.

## Navigace a routy

Autentizované hlavní menu vždy obsahuje:

- `Vratky` / `Zwroty` → `GET /returns`, route `returns.index`;
- `Doklady a finance` / `Dokumenty i finanse` → `GET /finance`, route `finance.index`.

Aktivní položka používá serverově odvozené `aria-current="page"`. Obě routy jsou uvnitř stávajícího middleware řetězce `auth`, `active`, `password.changed` a controller navíc autorizuje `Order::viewAny`.

## Vratky

Stránka načítá výhradně `CORE_RETURN` thready dostupné přes `ProcessThread::visibleTo($user)`. Zobrazuje číslo zakázky, produkt, stav, lokaci vratného kusu, odpovědnou stranu, stav dodavatelské zálohy a následující krok. Filtry rozdělují čekání na AUTOKLUBY, Polsko, dobropis a platbu; nulový výsledek má stabilní prázdný stav.

Stav dodavatelské zálohy se odvozuje pouze z účinných `finance_entries`. Zákaznický `business_snapshot` se nenačítá ani nezobrazuje.

## Doklady a finance

Read-only fronty obsahují:

- větve bez aktivní dodavatelské faktury;
- vratky čekající na rozhodnutí o záloze;
- schválená vrácení bez aktivního dobropisu;
- vystavené dobropisy čekající na platbu;
- vratky s účinným krácením dodavatelské zálohy.

Každá položka odkazuje na autorizovaný detail zakázky. Detail má sekční navigaci Přehled, Vratka a Doklady a finance; odkazy se zobrazí jen tehdy, když má uživatel příslušnou viditelnou větev nebo již existující oprávnění k finanční sekci.

## Oprávnění a soukromí

AUTOKLUBY používá úplný interní scope. Polsko dostane pouze aktuálně nebo historicky přiřazené thready podle existujícího `visibleTo()` scope. Projekce vybírá z order pouze `id`, `order_no` a `product_code`; nenačítá customer relation, kontaktní údaje ani zákaznickou zálohu. Stejná pravidla platí při přímém otevření `/returns` a `/finance`, nejen při vykreslení menu.

## Ověření

`Phase4dNavigationTest` pokrývá pojmenované routy, autentizaci přímých URL, vždy viditelné a aktivní menu, CZ/PL texty, prázdné fronty, všechny vratkové filtry a finanční fronty, odkazy do detailu, polský row-level scope a absenci PII i zákaznické zálohy.

Testy se spouštějí výhradně přes `.env.testing` a SQLite `:memory:`. Pokud je na serveru aktivní produkční config cache, testovací příkaz používá samostatnou neexistující `APP_CONFIG_CACHE` cestu pod systémovým temp adresářem; produkční cache se nemaže ani nemění.

Finální `composer check` prošel: 309 testů, 1261 assertions.

Ve Fázi 4D nebyla přidána ani spuštěna žádná migrace a nebyla změněna produkční DB ani produkční document storage.
