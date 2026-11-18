# Vratné díly `CORE_RETURN`

`CORE_RETURN` je povinná procesní větev skladové repase, nikoli globální stav objednávky. Má vlastní fyzický item role `CORE_RETURN`, historii událostí, kontroly, odpovědnost a `lock_version`.

## Příjem AUTOKLUBY

`CONFIRM_CORE_RECEIPT_AK` atomicky vytvoří nový, nepřepisovaný řádek `core_return_inspections`, thread event a audit a přepne větev z `WAITING_CORE` do `CORE_RECEIVED_AK`.

Povinná pole jsou:

- `piece_result`: stabilní kód `CORRECT_PIECE` nebo `DIFFERENT_PIECE`;
- `transport_condition`: stabilní kód `NO_VISIBLE_DAMAGE`, `PACKAGING_DAMAGED` nebo `ITEM_DAMAGED`;
- booleovské `accident`, `disassembled`, `corrosion`, `axial_rods`.

`note` je volitelná, maximálně 5000 znaků. Záznam vždy obsahuje actor user, organizaci a `inspected_at`. Výsledek `DIFFERENT_PIECE` nikdy automaticky nemění `orders.product_code`; jde pouze o výsledek konkrétní kontroly.

## Přeprava a Polsko

Z `CORE_RECEIVED_AK` lze fyzický kus vložit jen do `AK_TO_PL` zásilky s `GLS` nebo `SVOZ_AUTOKLUBY`. `SEND_SHIPMENT` serverem odvodí `SEND_TO_POLAND` a stav `SENT_TO_PL`. `CONFIRM_SHIPMENT_DELIVERY` provedené Polskem odvodí `CONFIRM_CORE_RECEIPT_PL`, přesune item do lokality `POLAND`, uvolní aktivní rezervaci a nastaví `CORE_INSPECTION`.

`CORE_RETURN` v této části procesu není způsobilý pro `PL_TO_AK`. Odeslání i příjem celé dávky zůstávají v jedné transakci `ShipmentService`; chyba jediné položky vrátí všechny thready, eventy, audity, shipment i rezervace.

## Oprávnění a viditelnost

- AUTOKLUBY operator/admin přijímá a kontroluje kus a odesílá jej do Polska.
- Polský manager/operator přijímá zásilku, eviduje dodavatelské hodnoty a rozhoduje vypořádání.
- Běžný AUTOKLUBY operator nemění polský finanční verdikt.
- `AK_ADMIN` může finanční rozhodnutí převzít pouze s `override_reason`; vznikne `CORE_RETURN_ADMIN_OVERRIDE` audit.
- Polsko získá row-level viditelnost konkrétní vratky až po historickém přiřazení/přechodu své organizaci. Zákaznické PII a `CUSTOMER_DEPOSIT_SNAPSHOT` nejsou součástí dodavatelského ledgeru.

## Dokončení

`DEPOSIT_FULL_APPROVED` a `DEPOSIT_REDUCED_APPROVED` nejsou koncové: čekají na aktivní dobropis přesně ve schválené částce a měně. Jeho atomické nahrání posune větev do `CREDIT_ISSUED`; potvrzení platby serverem odvozené z aktivního dobropisu a ledgeru ji posune do koncového `CREDIT_PAID`. `CORE_SETTLED_NO_DEPOSIT` je druhý koncový stav a dobropis nepovoluje. `OrderCompletionService` uzavře objednávku teprve tehdy, když jsou dokončené všechny její povinné větve.

UI detailu ukazuje fyzickou lokaci, kontrolu AUTOKLUBY, přepravu, schválenou částku, krácení, soukromé doklady, platbu a další povolenou akci. Polská projekce používá row-level scope a nenačítá zákaznické PII ani zákaznický snapshot zálohy.

Fáze 4C automatizovaně přijala všechny tři finanční cesty od `WAITING_CORE` do koncového stavu. Polsko nemůže přeskočit příjem AUTOKLUBY ani zásilku; fyzická lokace se mění společným shipment workflow. Důkaz a deployment brány jsou v `PHASE_4_ACCEPTANCE.md` a `PHASE_4_DEPLOYMENT.md`.
