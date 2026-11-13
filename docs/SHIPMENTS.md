# Zásilky AUTOKLUBY ↔ POLSKO

Fáze 3A zavádí doménu hromadných zásilek mezi organizacemi a Fáze 3B nad ní staví UI, QR skener a HTTP formuláře popsané v `docs/PHASE_3B.md`. Zákaznické přepravy nejsou součástí tohoto modelu.

## Povolená matice

| Direction | Carrier | Thread type | Zdrojový stav při vložení/odeslání | Akce při `SEND_SHIPMENT` | Stav po odeslání | Akce při `CONFIRM_SHIPMENT_DELIVERY` | Cílový stav po doručení |
|---|---|---|---|---|---|---|---|
| `AK_TO_PL` | `GLS` | `CUSTOM_REBUILD` | `RECEIVED_AK`, vstupní událost `CONFIRM_AK_RECEIPT` | `SEND_TO_POLAND` | `SENT_TO_PL` | `CONFIRM_PL_RECEIPT` | `RECEIVED_PL_REPAIR` |
| `AK_TO_PL` | `SVOZ_AUTOKLUBY` | `CUSTOM_REBUILD` | `RECEIVED_AK`, vstupní událost `CONFIRM_AK_RECEIPT` | `SEND_TO_POLAND` | `SENT_TO_PL` | `CONFIRM_PL_RECEIPT` | `RECEIVED_PL_REPAIR` |
| `PL_TO_AK` | `DPD` | `CUSTOM_REBUILD` | `REPAIRED_PL` | `SEND_TO_AUTOKLUBY` | `SENT_TO_AK` | `CONFIRM_AK_RETURN_RECEIPT` | `RECEIVED_AK` |
| `PL_TO_AK` | `SVOZ_AUTOKLUBY` | `CUSTOM_REBUILD` | `REPAIRED_PL` | `SEND_TO_AUTOKLUBY` | `SENT_TO_AK` | `CONFIRM_AK_RETURN_RECEIPT` | `RECEIVED_AK` |
| `PL_TO_AK` | `DPD` | `STOCK_REQUEST` | `PL_STOCK_AVAILABLE` | `SEND_TO_AUTOKLUBY` | `SENT_TO_AK` | `CONFIRM_AK_STOCK_RECEIPT` | `RECEIVED_AK` |
| `PL_TO_AK` | `SVOZ_AUTOKLUBY` | `STOCK_REQUEST` | `PL_STOCK_AVAILABLE` | `SEND_TO_AUTOKLUBY` | `SENT_TO_AK` | `CONFIRM_AK_STOCK_RECEIPT` | `RECEIVED_AK` |
| `PL_TO_AK` | `DPD` | `STOCK_REPLENISHMENT` | `PL_STOCK_AVAILABLE` | `SEND_TO_AUTOKLUBY` | `SENT_TO_AK` | `CONFIRM_AK_STOCK_RECEIPT` | `RECEIVED_AK` (větev dokončena) |
| `PL_TO_AK` | `SVOZ_AUTOKLUBY` | `STOCK_REPLENISHMENT` | `PL_STOCK_AVAILABLE` | `SEND_TO_AUTOKLUBY` | `SENT_TO_AK` | `CONFIRM_AK_STOCK_RECEIPT` | `RECEIVED_AK` (větev dokončena) |

Jiné kombinace jsou zakázané. Zejména DPD nelze použít pro `AK_TO_PL`, GLS nelze použít pro `PL_TO_AK` a čekající `CORE_RETURN` nemá ve Fázi 3A akci, která by jej opravňovala k vložení.

## Stav zásilky

| Výchozí stav | Command | Cílový stav | Oprávnění | Podmínky |
|---|---|---|---|---|
| — | `CREATE_SHIPMENT` | `PREPARED` | odesílající organizace; `AK_ADMIN` může vytvoření opačného směru auditovaně převzít | Povolený směr/dopravce; override vyžaduje důvod |
| `PREPARED` | `ADD_SHIPMENT_ITEM` | `PREPARED` | odesílající organizace | Způsobilý item/thread, lokalita odesílatele, žádná aktivní rezervace |
| `PREPARED` | `REMOVE_SHIPMENT_ITEM` | `PREPARED` | odesílající organizace | Item je v této zásilce a rezervován touto zásilkou |
| `PREPARED` | `SEND_SHIPMENT` | `SENT` | odesílající organizace | Neprázdná zásilka, tracking a všechny větve znovu validní |
| `SENT` | `MARK_TRANSPORT_ERROR` | `TRANSPORT_ERROR` | provozní uživatel odesílatele nebo příjemce | Neprázdný popis; bez změny threadů a bez uvolnění rezervací |
| `SENT`, `TRANSPORT_ERROR` | `CONFIRM_SHIPMENT_DELIVERY` | `DELIVERED` | přijímající organizace | Všechny větve dovolují serverem odvozenou příjmovou akci |

`DELIVERED` je koncový stav zásilky. Fyzický item se přesune do lokality příjemce a jeho aktivní rezervace se uvolní až při úspěšném doručení celé zásilky.

## Tracking

- `GLS` a `DPD` vyžadují při odeslání neprázdný tracking.
- `SVOZ_AUTOKLUBY` tracking nesmí obsahovat; svoz je dopravce, nikoli falešné číslo.
- Tracking je vlastnost celé zásilky, nikoli objednávky nebo jednotlivého itemu.
- Unikátní DB klíč `(carrier, tracking)` brání opakování stejné neprázdné kombinace. Nullable tracking umožňuje více svozů bez falešných hodnot.

## Fyzické kusy a historie

`shipment_items` uchovává historickou vazbu zásilky, itemu a procesní větve. Unikátní `(shipment_id, item_id)` zakazuje dvojí vložení do stejné zásilky.

Aktivní výlučná rezervace je samostatně uložena v `items.active_shipment_id`. Jeden řádek itemu tak může ukazovat nejvýše na jednu aktivní zásilku a FK dovolí pouze existující zásilku. Rezervace vzniká atomicky s `shipment_items`, při odebrání z `PREPARED` se zruší a po `SENT` zůstává až do `DELIVERED`. Stav `TRANSPORT_ERROR` ji neuvolňuje. Staré řádky `shipment_items` se po doručení nemažou, takže pozdější přeprava stejného kusu nepřepíše historii.

## Atomická orchestrace

`ShipmentService` je aplikační koordinátor, nikoli druhý workflow engine. Při odeslání nebo příjmu:

1. zamkne shipment a ověří jeho `lock_version`;
2. deterministicky zamkne shipment items, fyzické itemy a procesní větve;
3. předem ověří všechny vazby, lokality, rezervace, role, stavy a oprávnění;
4. v jedné vnější DB transakci zavolá stávající `CustomRebuildWorkflow` nebo `StockRebuildWorkflow` s akcí odvozenou serverem;
5. zapíše thread events/audity, shipment event/audit a nový stav zásilky;
6. při jediné chybě vrátí zpět celou dávku.

Klient nikdy neposílá cílový stav ani akci procesní větve. Vazba `shipment_items.process_thread_id` určuje thread a server použije tabulku výše.

## Idempotence a concurrency

- Každý mutační shipment command vyžaduje neprázdný idempotency key; kromě vytvoření také očekávaný `lock_version`.
- `shipment_events.idempotency_key` je globálně unikátní a událost ukládá hash akce, aktéra, payloadu, verze a identifikátorů objektů.
- Shodné opakování vrací původní událost. Stejný klíč s jiným obsahem je deterministický konflikt.
- Pro thread akce se odvozuje stabilní interní idempotency key ze zásilky, threadu, shipment commandu a akce.
- Row lock itemu společně s `active_shipment_id` zabrání dvěma pracovníkům rezervovat stejný kus do dvou aktivních zásilek.
- Unikátní omezení zůstávají poslední databázovou ochranou pro položku v zásilce, tracking i idempotency key.

## Audit

Každý command vytváří neměnný `shipment_event` a audit. Odeslání a příjem navíc vytvoří standardní `thread_events` a audity existujícího workflow. Bezpečná metadata obsahují interní ID itemu, threadu a objednávky; zásilka neukládá zákaznické PII.
