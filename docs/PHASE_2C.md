# Fáze 2C — skladová repase a paralelní procesní větve

Datum dokončení: 2026-11-12.

## Rozsah

Fáze implementuje doménové workflow `STOCK_REBUILD` bez UI a bez živého napojení na Pohodu. Import nadále zakládá pouze `STOCK_REQUEST/PENDING_STOCK_CHECK`. Teprve důvěryhodný resolver vracející aktuální `AVAILABLE_IN_AK` nebo `NOT_AVAILABLE_IN_AK` atomicky materializuje výsledné větve a fyzické kusy.

## Společný workflow engine

Původní command workflow `CUSTOM_REBUILD` i nové skladové workflow používají `ThreadWorkflowEngine`. Ten zajišťuje na jednom místě:

- zámek objednávky a větve;
- idempotency key a request hash;
- kontrolu očekávané `lock_version` a podmíněný update;
- transakční zápis stavu, `thread_event` a auditu;
- přepočet `orders.closed_at` přes `OrderCompletionService`.

Stavové automaty a guardy dodávají pouze povolené přechody, organizační oprávnění, povinná data a odpovědnost. `CUSTOM_REBUILD` byl na executor přepojen a jeho kompletní regresní cesta zůstává zelená.

## Důvěryhodné rozhodnutí skladu

Kontrakt `ResolvesStockAvailability` vrací hodnotový objekt s kódem, bezpečnou referencí zdroje a časem pozorování. Výchozí bezpečná implementace vrací `UNKNOWN`; neprovádí síťové ani SQL spojení s Pohodou. Feature testy používají izolovaný fixture resolver.

`UNKNOWN` a `STALE` ponechají pouze původní `PENDING_STOCK_CHECK`, nevytvoří kus, polskou větev ani polské oprávnění. Potvrzené rozhodnutí je jednorázově zapsáno jako `STOCK_AVAILABILITY_DECIDED`.

Stejné opakované rozhodnutí vrátí původní událost bez dalších větví, kusů nebo auditu. Konfliktní druhé rozhodnutí se neprovede, ale zapíše append-only audit `STOCK_AVAILABILITY_CONFLICT`. Objednávkový zámek a databázové unikátní klíče brání dvojité materializaci.

## Vzniklé větve a kusy

### `AVAILABLE_IN_AK`

- `STOCK_REQUEST/READY_AK_DISPATCH`, skladový kus `STOCK_ITEM` v AUTOKLUBY;
- `STOCK_REPLENISHMENT/WAITING_PL_STOCK`, náhradní kus `STOCK_REPLACEMENT` v Polsku;
- `CORE_RETURN/WAITING_CORE`, původní zákaznický kus `CORE_RETURN` u zákazníka.

### `NOT_AVAILABLE_IN_AK`

- `STOCK_REQUEST/WAITING_PL_STOCK`, objednaný `STOCK_ITEM` v Polsku;
- `CORE_RETURN/WAITING_CORE`, původní zákaznický kus `CORE_RETURN` u zákazníka.

Každý fyzický kus má vlastní náhodný QR token. Migrace `2026_11_12_000005_add_phase_2c_domain_guards.php` přidává `type_instance` a `role_instance` a unikátní omezení `(order_id, type, type_instance)` a `(order_id, role, role_instance)`. Výchozí instance 1 chrání singleton větve a role; sloupec současně ponechává prostor pro budoucí opakovatelné cykly.

## Oprávnění a viditelnost

- rozhodnutí Děčín/Polsko nemá HTTP endpoint a může je vyvolat jen systémová služba přes důvěryhodný resolver;
- `CONFIRM_PL_STOCK_AVAILABLE`, `SET_PL_STOCK_ETA` a `SEND_TO_AUTOKLUBY` patří rolím `PL_MANAGER`/`PL_OPERATOR` organizace POLAND;
- `CONFIRM_AK_STOCK_RECEIPT` a zákaznické předání patří `AK_ADMIN`/`AK_OPERATOR` organizace AUTOKLUBY;
- `ADMIN_OVERRIDE` patří pouze `AK_ADMIN`, vyžaduje neprázdný důvod a zapisuje událost i audit;
- polský thread scope zpřístupní pouze historicky přiřazený `STOCK_REQUEST`, `STOCK_REPLENISHMENT` nebo `CUSTOM_REBUILD`; čistě zákaznická větev a `CORE_RETURN` zůstávají skryté.

## Uzavření

`RECEIVED_AK` dokončí pouze `STOCK_REPLENISHMENT`. `SENT_CUSTOMER` nebo `PICKED_UP` dokončí pouze `STOCK_REQUEST`. `OrderCompletionService` nastaví `closed_at` až tehdy, když není aktivní žádná povinná větev. Proto skladová objednávka zůstává v této fázi otevřená kvůli `CORE_RETURN/WAITING_CORE`, i když bylo zákaznické plnění a případné doplnění skladu dokončeno.

## Ověření a stop bod

Unit a feature testy používají pouze SQLite testovací databázi, factories a fixture resolver. Pokrývají chybějící/zastaralý snapshot, přesnou materializaci 3/3 a 2/2, opakování, konflikt, souběžnou ekvivalenci, rollback, ETA a jeho změnu, dostupnost, odeslání/příjem, GLS/osobní odběr, organizační oprávnění, polský row scope, override a uzavření objednávky.

Produkční migrace, Pohoda bridge, scheduler, ostrý import ani Fáze 2D nebyly spuštěny. `LEGACY_SHOP_IMPORT_ENABLED=false` zůstává beze změny.
