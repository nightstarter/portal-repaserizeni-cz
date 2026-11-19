# Fáze 6A – doménové jádro opakovatelných reklamací

## Rozsah

Fáze 6A rozšiřuje existující `process_threads` o opakovatelné větve typu `WARRANTY`. Reklamace zůstává pod původním `orders.id` a `order_no`; nevzniká nová zákaznická objednávka. Každý fyzický kus (`items.id`) má vlastní řadu cyklů `warranty_cycle` začínající číslem 1.

Migrace `2026_11_19_000012_add_phase_6a_warranty_threads.php` přidává:

- `process_threads.item_id`, `created_by`, idempotency údaje založení;
- unikátní klíč `(order_id, type, item_id, warranty_cycle)`;
- index aktivních WARRANTY větví;
- `items.active_warranty_thread_id` jako databázový ukazatel aktivního cyklu.

Nový cyklus se zakládá v transakci se zámkem objednávky a kusu. Aktivní cyklus nebo aktivní zásilka založení odmítne. Po terminálním dokončení se aktivní ukazatel uvolní, historická větev i její append-only události zůstávají beze změny. `type_instance` je jedinečné v rámci objednávky, zatímco `warranty_cycle` se čísluje samostatně pro každý kus.

## Stavový automat

| Akce | Z | Do | Provádí |
|---|---|---|---|
| `CREATE_WARRANTY_CYCLE` | — | `WARRANTY_RECEIVED_AK` | AUTOKLUBY |
| `PREPARE_WARRANTY_TO_POLAND` | `WARRANTY_RECEIVED_AK` | `WARRANTY_READY_TO_PL` | AUTOKLUBY |
| `SEND_WARRANTY_TO_POLAND` | `WARRANTY_READY_TO_PL` | `WARRANTY_SENT_TO_PL` | ShipmentService / AUTOKLUBY |
| `CONFIRM_WARRANTY_POLAND_RECEIPT` | `WARRANTY_SENT_TO_PL` | `WARRANTY_RECEIVED_PL` | ShipmentService / Polsko |
| `COMPLETE_WARRANTY_REPAIR` | `WARRANTY_RECEIVED_PL` | `WARRANTY_REPAIR_COMPLETED` | Polsko |
| `PREPARE_WARRANTY_TO_AUTOKLUBY` | `WARRANTY_REPAIR_COMPLETED` | `WARRANTY_READY_TO_AK` | Polsko |
| `SEND_WARRANTY_TO_AUTOKLUBY` | `WARRANTY_READY_TO_AK` | `WARRANTY_SENT_TO_AK` | ShipmentService / Polsko |
| `CONFIRM_WARRANTY_AUTOKLUBY_RECEIPT` | `WARRANTY_SENT_TO_AK` | `WARRANTY_RECEIVED_AK_BACK` | ShipmentService / AUTOKLUBY |
| `SEND_WARRANTY_TO_CUSTOMER` | `WARRANTY_RECEIVED_AK_BACK` | `WARRANTY_SENT_TO_CUSTOMER` | AUTOKLUBY |
| `CONFIRM_WARRANTY_CUSTOMER_PICKUP` | `WARRANTY_RECEIVED_AK_BACK` | `WARRANTY_PICKED_UP_BY_CUSTOMER` | AUTOKLUBY |

Poslední dva stavy jsou alternativní terminální konce. Přímé nastavení stavu je odmítnuto. `WARRANTY_ADMIN_OVERRIDE` je dostupný pouze `AK_ADMIN`, vyžaduje potvrzení a neprázdný důvod a zapisuje samostatnou auditní akci.

## Zásilky a atomická konzistence

Přeprava AUTOKLUBY ↔ Polsko používá výhradně existující `ShipmentService`, `shipment_items`, směrové kontroly, rezervaci `items.active_shipment_id`, tracking a QR tok. Odeslání i potvrzení doručení mění shipment a WARRANTY větev v jedné databázové transakci. Událost větve obsahuje `shipment_id` a bezpečné veřejné číslo zásilky. Opakování stejného idempotency klíče vrací původní událost.

Zákaznické odeslání a osobní odběr jsou doménové akce bez vytvoření zásilky nebo falešného trackingu.

## Oprávnění a data Polska

- `AK_OPERATOR` a `AK_ADMIN` mohou cyklus založit a provádět akce AUTOKLUBY.
- `PL_MANAGER` a `PL_OPERATOR` mohou provádět pouze polské akce u větve dostupné přes existující `visibleTo` row-level scope.
- `READ_ONLY` nemůže založit cyklus ani provést akci.
- Cizí objednávka nebo WARRANTY větev na command endpointu vrací 404.
- Command odpověď obsahuje jen ID větve, číslo cyklu, stav a `lock_version`; neobsahuje zákaznické PII.

## Routy

- `POST /orders/{order}/items/{item}/warranty` – `orders.warranty.store`
- `POST /orders/{order}/warranty/{thread}/actions/{action}` – `orders.warranty.actions`

Obě routy jsou pod existujícím autentizačním, active-user a changed-password middlewarem, používají web CSRF ochranu a vyžadují idempotency key. Akční endpoint navíc povinně vyžaduje `expected_lock_version`.

## Audit a izolace

Každá změna zapisuje `thread_events` a `audit_log` ve stejné transakci. Událost obsahuje cyklus, kus, předchozí/nový stav, aktéra, organizaci, odpovědnou organizaci, čas a idempotency key; u přepravy také vazbu na zásilku. Dokončenou větev aplikační služba dále měnit nedovolí.

WARRANTY workflow nevolá globální `OrderCompletionService` a nemění původní procesní větve, ceny, finance ani společnou diskusi objednávky.

## Ověření

Testy mají fail-fast guard `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Pokrývají oba opakované cykly, dva kusy, souběžnou rezervaci kusu, celý automat, stale verzi, idempotenci, transakční rollback, obě přepravní trasy, chybný směr, dvojí aktivní zásilku, role, 404, PII, izolaci existujících domén a zákaz falešného trackingu.

## Známá omezení fáze 6A

- Fáze záměrně neobsahuje finální seznam, formuláře a prezentaci historie; ty náleží do 6B/6C.
- Databáze zaručuje jedinečnost čísla cyklu; pravidlo jediného aktivního cyklu kombinuje ukazatel na kusu, řádkový zámek a aplikační transakci. Přímé zápisy mimo aplikační službu nejsou podporované.
- Row-level přiřazení Polska zůstává na úrovni existující organizace a historie odpovědnosti, nikoli individuálního uživatele.
- Migrace je pouze rozšiřující, ale na velkých produkčních tabulkách může vytvoření indexů a cizích klíčů krátce uzamknout tabulku; nasazení vyžaduje samostatné údržbové okno.
