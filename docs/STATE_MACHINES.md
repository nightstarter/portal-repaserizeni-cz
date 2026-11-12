# Stavové automaty

## CUSTOM_REBUILD — fáze 2A a UI projekce 2B

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

### UI projekce Fáze 2B

Dashboard, detail a QR stránka neukládají další univerzální status. `CustomRebuildPresentation` vždy odvodí stav, odpovědnost, poslední událost a další povolenou akci z větve a její historie. Čtecí projekce i zápisový příkaz používají stejný `CustomRebuildActionGuard`; samotné skrytí tlačítka proto není autorizační mechanismus.

Formuláře předávají pouze stabilní kód akce, idempotency key, očekávanou `lock_version` a data dané akce. Cílový stav běžné akce se v UI neposílá. `ADMIN_OVERRIDE` je zvláštní auditovaný příkaz dostupný pouze `AK_ADMIN`; UI navíc vyžaduje explicitní potvrzení, ale autoritou zůstává command služba.

Polsko získá row-level přístup až historickým přiřazením konkrétní `CUSTOM_REBUILD` větve své organizaci. Tento serverový scope je společný pro dashboard, detail a QR. QR token je pouze náhodný vyhledávací identifikátor, nikoli oprávnění.

## STOCK_REBUILD — fáze 2C

Import vytvoří právě jednu větev `STOCK_REQUEST/PENDING_STOCK_CHECK` odpovědnou AUTOKLUBY. Browser nemá akci, kterou by mohl tvrdit dostupnost v Děčíně. Důvěryhodná systémová služba přijímá pouze následující rozhodnutí:

| Výsledek resolveru | Dopad |
| --- | --- |
| `UNKNOWN`, `STALE` | Bez změny; zůstává pouze `PENDING_STOCK_CHECK`, bez polské viditelnosti |
| `AVAILABLE_IN_AK` | `STOCK_REQUEST/READY_AK_DISPATCH`, `STOCK_REPLENISHMENT/WAITING_PL_STOCK`, `CORE_RETURN/WAITING_CORE`; 3 odlišné kusy |
| `NOT_AVAILABLE_IN_AK` | `STOCK_REQUEST/WAITING_PL_STOCK`, `CORE_RETURN/WAITING_CORE`; 2 odlišné kusy |

Rozhodnutí, všechny nové větve, fyzické kusy, `thread_events` a audit vznikají v jedné transakci.

### Polská skladová část

Stejný automat platí pro `STOCK_REQUEST` i `STOCK_REPLENISHMENT`:

| Výchozí stav | Akce | Organizace / role | Cílový stav | Nová odpovědnost | Podmínka |
| --- | --- | --- | --- | --- | --- |
| `WAITING_PL_STOCK` | `CONFIRM_PL_STOCK_AVAILABLE` | POLAND / `PL_MANAGER`, `PL_OPERATOR` | `PL_STOCK_AVAILABLE` | POLAND | — |
| `WAITING_PL_STOCK` | `SET_PL_STOCK_ETA` | POLAND / `PL_MANAGER`, `PL_OPERATOR` | `PL_SECURING_STOCK` | POLAND | Budoucí ETA povinné |
| `PL_SECURING_STOCK` | `SET_PL_STOCK_ETA` | POLAND / `PL_MANAGER`, `PL_OPERATOR` | `PL_SECURING_STOCK` | POLAND | Nové budoucí ETA povinné |
| `PL_SECURING_STOCK` | `CONFIRM_PL_STOCK_AVAILABLE` | POLAND / `PL_MANAGER`, `PL_OPERATOR` | `PL_STOCK_AVAILABLE` | POLAND | — |
| `PL_STOCK_AVAILABLE` | `SEND_TO_AUTOKLUBY` | POLAND / `PL_MANAGER`, `PL_OPERATOR` | `SENT_TO_AK` | AUTOKLUBY | — |
| `SENT_TO_AK` | `CONFIRM_AK_STOCK_RECEIPT` | AUTOKLUBY / `AK_ADMIN`, `AK_OPERATOR` | `RECEIVED_AK` | AUTOKLUBY | — |

Akční kód `SEND_TO_AUTOKLUBY` je záměrně společný s `CUSTOM_REBUILD`; jednoznačný význam určuje typ zamčené větve a její stavový automat. U `STOCK_REPLENISHMENT` je `RECEIVED_AK` koncový stav. U `STOCK_REQUEST` pokračuje zákaznické předání.

### Zákaznické předání `STOCK_REQUEST`

| Výchozí stav | Akce | Organizace / role | Cílový stav | Dokončení |
| --- | --- | --- | --- | --- |
| `READY_AK_DISPATCH` nebo `RECEIVED_AK` | `SEND_TO_CUSTOMER` | AUTOKLUBY / `AK_ADMIN`, `AK_OPERATOR` | `SENT_CUSTOMER` | větev dokončena; pouze GLS |
| `READY_AK_DISPATCH` nebo `RECEIVED_AK` | `MARK_READY_PICKUP` | AUTOKLUBY / `AK_ADMIN`, `AK_OPERATOR` | `READY_PICKUP` | ne; pouze osobní odběr |
| `READY_PICKUP` | `CONFIRM_CUSTOMER_PICKUP` | AUTOKLUBY / `AK_ADMIN`, `AK_OPERATOR` | `PICKED_UP` | větev dokončena |

### `CORE_RETURN` a uzavření objednávky

Fáze 2C pouze zakládá `CORE_RETURN/WAITING_CORE` s odpovědností zákazníka. Nemá žádné běžné přechody; vratkový a finanční automat vznikne ve Fázi 4.

Dokončení jedné větve neuzavírá objednávku. `orders.closed_at` přepočítává společná doménová služba až po dokončení všech povinných větví. Aktivní `CORE_RETURN`, `STOCK_REPLENISHMENT` nebo jiná povinná větev drží objednávku otevřenou.

### UI projekce Fáze 2D

Dashboard a detail zobrazují jednotlivé `process_threads` jako samostatné řádky a karty. Fronty, „Kdo je na tahu“, ETA, dokončení a hlavní další akce jsou čtecí projekce větví; žádná z nich není další stav objednávky. Povolené akce odvozuje stejný `StockRebuildActionGuard`, který používá `StockRebuildWorkflow` při zápisu.

HTTP formuláře posílají pouze akční kód, typ větve, idempotency key, očekávanou `lock_version` a data akce. Neexistuje browser route pro skladové rozhodnutí `AVAILABLE_IN_AK` / `NOT_AVAILABLE_IN_AK`. `PENDING_STOCK_CHECK` pouze zobrazuje stav čtecího resolveru a stáří snapshotu.

Polský query scope zpřístupní jen historicky přidělený `STOCK_REQUEST` nebo `STOCK_REPLENISHMENT`. Stejná hranice platí pro detail a QR konkrétního kusu. Role kusu se mapuje právě na jednu větev: `STOCK_ITEM → STOCK_REQUEST`, `STOCK_REPLACEMENT → STOCK_REPLENISHMENT`, `CORE_RETURN → CORE_RETURN`.

## Mimo fáze 2A–2D

Detailní vratky, zásilky, tracking, finance, dokumenty, diskuse, notifikace, reklamace a výkupy zde nejsou implementované. Budou specifikovány až v příslušných dalších fázích.
