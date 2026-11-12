# Stavové automaty

## CUSTOM_REBUILD — fáze 2A

Uživatel neposílá cílový stav. Posílá pouze stabilní kód akce a povinný idempotency key. Cílový stav, odpovědnost a dokončení odvozuje serverová aplikační služba.

| Výchozí stav | Akce | Oprávněná organizace / role | Cílový stav | Nová odpovědnost | Podmínka |
| --- | --- | --- | --- | --- | --- |
| `WAITING_CUSTOMER_ITEM` | `CONFIRM_AK_RECEIPT` | AUTOKLUBY / `AK_ADMIN`, `AK_OPERATOR` | `RECEIVED_AK` | AUTOKLUBY | Kompletní kontrolní údaje příjmu |
| `RECEIVED_AK` | `SEND_TO_POLAND` | AUTOKLUBY / `AK_ADMIN`, `AK_OPERATOR` | `SENT_TO_PL` | POLAND | Do `RECEIVED_AK` vedla akce `CONFIRM_AK_RECEIPT` |
| `SENT_TO_PL` | `CONFIRM_PL_RECEIPT` | POLAND / `PL_MANAGER`, `PL_OPERATOR` | `RECEIVED_PL_REPAIR` | POLAND | — |
| `RECEIVED_PL_REPAIR` | `MARK_REPAIRED_PL` | POLAND / `PL_MANAGER`, `PL_OPERATOR` | `REPAIRED_PL` | POLAND | — |
| `REPAIRED_PL` | `SEND_TO_AUTOKLUBY` | POLAND / `PL_MANAGER`, `PL_OPERATOR` | `SENT_TO_AK` | AUTOKLUBY | — |
| `SENT_TO_AK` | `CONFIRM_AK_RETURN_RECEIPT` | AUTOKLUBY / `AK_ADMIN`, `AK_OPERATOR` | `RECEIVED_AK` | AUTOKLUBY | — |
| `RECEIVED_AK` | `SEND_TO_CUSTOMER` | AUTOKLUBY / `AK_ADMIN`, `AK_OPERATOR` | `SENT_CUSTOMER` | CUSTOMER | Do `RECEIVED_AK` vedla `CONFIRM_AK_RETURN_RECEIPT`; předání `GLS` |
| `RECEIVED_AK` | `MARK_READY_PICKUP` | AUTOKLUBY / `AK_ADMIN`, `AK_OPERATOR` | `READY_PICKUP` | CUSTOMER | Do `RECEIVED_AK` vedla `CONFIRM_AK_RETURN_RECEIPT`; předání `PERSONAL_PICKUP` |
| `READY_PICKUP` | `CONFIRM_CUSTOMER_PICKUP` | AUTOKLUBY / `AK_ADMIN`, `AK_OPERATOR` | `PICKED_UP` | CUSTOMER | — |

Koncové stavy jsou pouze `SENT_CUSTOMER` a `PICKED_UP`. Pouze přechod do jednoho z nich nastaví `process_threads.completed_at` a `orders.closed_at`. `READY_PICKUP` není dokončení.

### Opakovaný stav `RECEIVED_AK`

Stabilní kód `RECEIVED_AK` zůstává společný pro první i zpětný příjem. Model je rozlišuje bezpečně podle poslední neměnné události zamčené větve:

- `CONFIRM_AK_RECEIPT → RECEIVED_AK` povoluje jen `SEND_TO_POLAND`;
- `CONFIRM_AK_RETURN_RECEIPT → RECEIVED_AK` povoluje jen zákaznickou větev podle způsobu předání.

Samotná hodnota stavu tedy nestačí; aplikační služba vždy kontroluje i vstupní událost. `ADMIN_OVERRIDE` do `RECEIVED_AK` žádnou běžnou navazující akci neodemkne, protože nemá jednu z uvedených vstupních událostí.

### Příjem AUTOKLUBY

`CONFIRM_AK_RECEIPT` atomicky ukládá na zákaznický fyzický kus:

- `inspection_result`: `CORRECT` nebo `DIFFERENT`;
- volitelný `condition_description`;
- potvrzený routing objednávky `KRAFT` nebo `ARGO`;
- `fault_code`: `OIL_LEAK`, `KNOCKING`, `FAULTY_UNIT` nebo `UNKNOWN`;
- volitelný `fault_note`;
- povinnou booleovskou informaci `axial_rods`.

Routing se mění pouze ve snapshotu konkrétní objednávky a její větve. Produktový registr se nemění. Částka 700 Kč je pouze odvozená doménová hodnota z `axial_rods=true`; nevzniká finanční položka, doklad ani platba.

### Bezpečnost příkazů

- Větev a objednávka se načítají v transakci pomocí `FOR UPDATE`; změna navíc používá podmínku na `lock_version`.
- Každá akce zapisuje stav, `thread_event` a audit v jedné transakci.
- `(process_thread_id, idempotency_key)` je unikátní. Stejný klíč a shodný příkaz vrátí původní událost; stejný klíč s jiným příkazem skončí konfliktem.
- Volitelné `expected_lock_version` odmítne zastaralý souběžný požadavek bez částečného zápisu.
- Běžné akce odmítají pole `state`, `to_state` a `target_state`.
- `ADMIN_OVERRIDE` smí použít pouze aktivní `AK_ADMIN` organizace AUTOKLUBY. Vyžaduje neprázdný důvod, idempotency key a zapisuje plnou událost i audit.

Všechny ostatní kombinace stavu a akce jsou zakázané.

## Mimo fázi 2A

Stavové automaty skladových větví, zásilek, vratek, financí, dokumentů, diskuse, notifikací, reklamací a výkupů zde nejsou implementované. Budou specifikovány až v příslušných dalších fázích.
