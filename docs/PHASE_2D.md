# Fáze 2D — UI skladové repase a paralelních větví

Datum dokončení: 2026-11-12.

## Rozsah

Fáze zpřístupňuje existující doménové služby Fáze 2C v responzivním server-rendered UI. Stav nemění controller ani formulář: HTTP vrstva sestaví `StockRebuildCommand` a volá výhradně `StockRebuildWorkflow`. Povolené akce pro dashboard, detail i QR odvozuje `StockRebuildActionGuard`, který používá také zápisová cesta.

## Dashboardy a fronty

AUTOKLUBY pracuje s řádky jednotlivých větví, nikoli s globálním stavem objednávky. Filtry pokrývají čekání na skladový snapshot, zákaznické plnění, doplnění skladu, vratný díl, odpovědnost AUTOKLUBY/POLAND a dokončené větve. Řádek zobrazuje typ a stav větve, odpovědnost, ETA, poslední událost a jednu hlavní další akci.

Polsko dostává pouze serverově povolené `STOCK_REQUEST` a `STOCK_REPLENISHMENT` větve. Samostatné fronty rozlišují nový požadavek, zajišťování kusu, dostupný kus, odeslaný kus a dokončenou relevantní větev. `PENDING_STOCK_CHECK`, čistě zákaznický požadavek z Děčína a `CORE_RETURN` nejsou do polského dotazu zahrnuté.

## Pending skladové rozhodnutí

Detail `PENDING_STOCK_CHECK` zobrazuje výsledek čtecího kontraktu `ResolvesStockAvailability`: chybějící nebo zastaralý snapshot a čas posledního pozorování, pokud existuje. UI nemá route ani formulář pro ruční tvrzení dostupnosti v Děčíně. Materializaci větví nadále smí provést jen `StockBranchDecisionService` s důvěryhodným resolverem.

## Detail, akce a časová osa

Skladový detail má samostatné karty zákaznického plnění, doplnění skladu a povinné čekající vratky. Každá karta ukazuje stav, odpovědnost, ETA, poslední událost a aktuálně povolené akce. Formuláře obsahují CSRF token, idempotency key a očekávanou `lock_version`; konflikt vrací uživatele na aktuální stav bez částečného zápisu.

`SET_PL_STOCK_ETA` vyžaduje platné budoucí datum a čas. Každá změna prochází command službou a vytváří samostatnou událost. Časová osa skládá události všech viditelných větví. Polsku poskytuje pouze bezpečná metadata z allowlistu a nezobrazuje admin důvody, auditní payloady ani zákaznické PII.

## Fyzické kusy a QR

Kusy `STOCK_ITEM`, `STOCK_REPLACEMENT` a `CORE_RETURN` jsou zobrazené odděleně s lokalitou, rolí, související větví a náhodným QR tokenem. QR controller kromě oprávnění k objednávce kontroluje také oprávnění ke konkrétní větvi odvozené z role kusu. Polský uživatel proto nemůže přes QR otevřít zákaznický kus z Děčína ani čekající vratku.

Mobilní QR detail prioritně ukazuje aktuální stav kusu, jeho větev a povolené akce. Nepřihlášený uživatel stále prochází loginem s návratem na původní URL.

## Dokončení objednávky

Detail počítá dokončené a aktivní povinné větve a vypisuje, které větve brání uzavření. Dokončený `STOCK_REQUEST` se neprezentuje jako uzavřená objednávka, pokud zůstává aktivní `STOCK_REPLENISHMENT` nebo `CORE_RETURN`. Autoritou pro `closed_at` zůstává `OrderCompletionService`.

## Ověření a stop bod

Feature testy nad SQLite testovací databází pokrývají oba dashboardy, všechny polské fronty, pending snapshot, zákaz ručního rozhodnutí, row-level hranice a PII, ETA včetně odmítnutí minulosti, polské a české akce, GLS/osobní odběr, oddělené QR kusy, vysvětlení otevřené objednávky, konflikt verze, idempotentní opakování, lokalizaci a regresní UI `CUSTOM_REBUILD`.

V připojeném executor prostředí nebyl dostupný Chromium, Node/Playwright ani nástroj sdíleného cloudového desktopu, proto nebylo možné pořídit browser screenshoty. Desktopový a mobilní výstup je ověřen feature testy renderovaného HTML a responzivními breakpointy bez produkčních dat.

Živé napojení Pohody, produkční migrace, import, scheduler, zásilky, finance ani vratkový automat nebyly spuštěny. `LEGACY_SHOP_IMPORT_ENABLED=false` zůstává beze změny.
