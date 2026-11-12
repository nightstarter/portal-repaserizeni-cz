# Rozhodnutí

## D-001: Laravel 13 a PHP 8.5

- Datum: 2026-11-11
- Stav: přijato podle IMPLEMENTACE v18
- Kontext: Aktuální stabilní Laravel 13 vyžaduje PHP 8.3+. Web i CLI nyní používají PHP 8.5.12 se všemi potřebnými moduly.
- Rozhodnutí: Cílit na Laravel 13/PHP 8.5. Nepoužít dostupný zastaralý Laravel 10.
- Dopad: Produkční web, CLI, cron a CI musí používat PHP 8.5.

## D-002: Import zůstává mimo Fázi 0

- Datum: 2026-11-11
- Stav: přijato podle IMPLEMENTACE v18
- Rozhodnutí: Ve Fázi 0 nevytvářet legacy DB připojení, importní příkaz ani scheduler. Zachovat pouze bezpečně vypnutou konfigurační hodnotu `LEGACY_SHOP_IMPORT_ENABLED=false`.

## D-003: Fáze 1A je pouze datové jádro

- Datum: 2026-11-12
- Stav: přijato podle explicitního zadání vlastníka
- Rozhodnutí: Fáze 1A obsahuje doménové tabulky a modely, továrnu počátečních větví a read-only dashboard/detail. Legacy připojení, mapování, scheduler a import jsou mimo 1A.
- Dopad: `LEGACY_SHOP_IMPORT_ENABLED=false`; testovací data vznikají výhradně přes factories a testy.

## D-004: Odpovědnost zákazníka není falešná organizace

- Datum: 2026-11-12
- Stav: přijato podle MASTER v17
- Rozhodnutí: `process_threads` ukládá stabilní `responsible_party` (`CUSTOMER`, `AUTOKLUBY`, `POLAND`, `SYSTEM`) a volitelnou vazbu na organizaci. Čekání na zákazníka tedy nevytváří fiktivní organizaci.
- Dopad: „Kdo je na tahu“ lze odvodit i pro zákaznické kroky a row-level oprávnění Polska se stále opírá o skutečnou organizaci.

## D-005: Scénář skladové zakázky musí být explicitní

- Datum: 2026-11-12
- Stav: přijato pro Fázi 1A
- Rozhodnutí: Továrna počátečních větví přijímá explicitní scénář `STOCK_DECIN` nebo `STOCK_POLAND`; dostupnost skladu se nehádá a nečte z produkčních systémů.
- Dopad: Budoucí import/Pohoda adaptér musí scénář určit z ověřených dat před zavoláním továrny.

## D-006: Legacy mapování je pouze explicitní a ověřené

- Datum: 2026-11-12
- Stav: přijato pro Fázi 1B
- Rozhodnutí: Schéma a významy legacy e-shopu se nesmí odhadovat. Tabulka, sloupce, uhrazené stavy a všechny procesní mapy se konfigurují přes `.env`; import je odmítne, dokud není mapování ručně označeno jako ověřené.
- Dopad: Diagnostiku lze použít k získání anonymizovaných podkladů, ale neznámé hodnoty vytvoří viditelnou integrační chybu místo tichého doplnění.

## D-007: Import je bezpečně vypnutý a bez scheduleru

- Datum: 2026-11-12
- Stav: přijato pro Fázi 1B
- Rozhodnutí: Zápisový import vyžaduje `LEGACY_SHOP_IMPORT_ENABLED=true`, povinnou startovní hranici a ověřené mapování. Fáze 1B nezapíná produkční připojení, cron ani scheduler a končí před živým ověřením.
- Dopad: Kód lze ověřit na fixture databázi a později spustit v dry-run režimu, ale v aktuálním výchozím nastavení nemůže vytvářet objednávky.
