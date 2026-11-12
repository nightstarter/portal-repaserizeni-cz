# Fáze 2B — UI zakázkové repase, časová osa a QR detail

Datum dokončení: 2026-11-12.

## Rozsah

Fáze zpřístupňuje existující command workflow `CUSTOM_REBUILD` z Fáze 2A uživatelům AUTOKLUBY a POLSKO. Kontrolery ani šablony nepočítají přechody samostatně: seznam povolených akcí i provedení příkazu používají společný serverový `CustomRebuildActionGuard` a stav mění výhradně `CustomRebuildWorkflow`.

Implementováno:

- dashboard AUTOKLUBY se stavem, odpovědností, další akcí a poslední událostí odvozenými serverovou prezentační službou;
- polský dashboard omezený query scopem pouze na zakázkové větve, které byly Polsku skutečně přiděleny;
- detail zakázky s kartami objednávky, produktu a vozidla, větve, fyzického kusu, příjmových údajů a časové osy;
- formuláře pouze aktuálně povolených akcí s CSRF, novým idempotency key a očekávanou verzí větve;
- mobilní formulář `CONFIRM_AK_RECEIPT`, který volá command službu z Fáze 2A;
- polské ovládání omezené na `CONFIRM_PL_RECEIPT`, `MARK_REPAIRED_PL` a `SEND_TO_AUTOKLUBY` v odpovídajících stavech;
- `ADMIN_OVERRIDE` viditelný pouze `AK_ADMIN`, s povinným cílem, důvodem a potvrzením;
- autentizovaný mobilní QR detail `/q/{randomToken}` bez veřejného sekvenčního ID;
- české a polské překlady stavů, akcí, polí a validačních hlášek.

## Oprávnění a ochrana dat

- Znalost QR tokenu sama o sobě nezakládá oprávnění. Nepřihlášený uživatel je vrácen na původní QR URL po přihlášení; neplatný i cizí token vrací shodné `404`.
- AUTOKLUBY používá existující přístup ke všem relevantním zakázkám. Polsko získá přístup jen přes historii přiřazení konkrétní `CUSTOM_REBUILD` větve; stejný scope používá seznam, detail i QR.
- Polské dotazy nenačítají zákaznický vztah. Dashboard ani detail nezobrazují adresu, telefon, e-mail nebo interní poznámky AUTOKLUBY.
- Časová osa vzniká z `thread_events` a pro AUTOKLUBY i z přítomnosti auditu. Polsko dostává pouze allowlist provozních metadat; admin důvody, interní auditní hodnoty a zákaznické PII se nepropouštějí.
- Skrytí ovládacího prvku není bezpečnostní hranice. Přímý POST stále ověřuje stav, organizaci, roli, idempotenci a `lock_version` v existující command službě.

## Konflikty a opakování

Každý vykreslený formulář obsahuje nově vygenerovaný idempotency key a aktuální `lock_version`. Úspěšný POST používá redirect, takže refresh neopakuje zápis. Shodné opakování vrátí původní událost. Zastaralá verze zobrazí aktuální stav a command transakce neprovede částečný zápis.

## Ověření

Feature testy nad SQLite databází v paměti pokrývají oba dashboardy, polské row-level oprávnění a PII hranici, povolené i přímé zakázané akce, CSRF/idempotenci, konflikt verze, QR login návrat a nerozlišitelné cizí tokeny, CZ/PL lokalizaci, `ADMIN_OVERRIDE` a bezpečnou polskou časovou osu.

Celý testovací balík: 198 testů, 516 assertions. Produkční databáze nebyla migrována.

## Mimo rozsah

Fáze nepřidává skladové workflow, zásilky, finance, dokumenty, diskusi, notifikace ani ostrý import. Scheduler nebyl spuštěn a `LEGACY_SHOP_IMPORT_ENABLED=false` zůstává beze změny.
