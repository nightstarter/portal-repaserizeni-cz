# Dodavatelské finance

## Dvě různé zálohy

`CUSTOMER_DEPOSIT_SNAPSHOT` pochází z legacy e-shopu a zůstává pouze referenčním snapshotem pro AUTOKLUBY. Polsku se neprezentuje jako dodavatelská záloha, portál jej nerefunduje zákazníkovi a doménová služba výslovně odmítá jeho použití jako zdroje dodavatelského zápisu.

`SUPPLIER_DEPOSIT` zadává Polsko z polské dodavatelské faktury. Má vlastní decimal částku a třípísmennou ISO měnu. Částka se nikdy automaticky nekopíruje ze zákaznického snapshotu.

## Neměnný ledger

`finance_entries` je append-only účetní historie navázaná na order, `CORE_RETURN` thread, thread event a audit. Každý řádek nese decimal(18,2), ISO měnu, stav, actor user a organizaci, důvod, idempotency key, request hash a timestamp.

Stabilní typy jsou:

- `SUPPLIER_PURCHASE_PRICE`;
- `SUPPLIER_DEPOSIT_ORIGINAL`;
- `SUPPLIER_DEPOSIT_NOT_CHARGED` — výslovný obchodní důkaz, nikoli pouhá absence;
- `SUPPLIER_DEPOSIT_RETURN_APPROVED`;
- `SUPPLIER_DEPOSIT_REDUCTION`;
- `SUPPLIER_DEPOSIT_SETTLED_NO_DEPOSIT`;
- `CORRECTION` a `REVERSAL` s vazbou `corrects_entry_id`.

Existující rozhodnutí se neupravuje. Oprava je nový `CORRECTION` nebo záporný `REVERSAL`, pouze pro `AK_ADMIN`, s povinným důvodem, stejnou měnou a auditní vazbou.

## Rozhodnutí

Plné vrácení vyžaduje potvrzený `SUPPLIER_DEPOSIT_ORIGINAL`. Klient neposílá částku; server převezme původní amount a currency a vytvoří `SUPPLIER_DEPOSIT_RETURN_APPROVED`.

Krácené vrácení vyžaduje finální vracenou částku ve stejné měně a neprázdný důvod. Server počítá výhradně v minor units bez `float`:

`reduction = original supplier deposit - approved return`

Finální částka musí být od nuly včetně do původní částky bez rovnosti nahoře. Rovnost vyžaduje plnou akci, vyšší či záporná částka je chyba. Vzniknou dva řádky: schválené vrácení a odvozené krácení.

Bez-zálohová cesta je možná jen po samostatném `SUPPLIER_DEPOSIT_NOT_CHARGED`. `NULL`, nula bez důkazní události ani chybějící originál nestačí. `CONFIRM_NO_SUPPLIER_DEPOSIT` nevytváří dobropis; přidá pouze `SUPPLIER_DEPOSIT_SETTLED_NO_DEPOSIT`, audit a dokončí větev.

## Transakce a souběh

Každý command vyžaduje idempotency key a přesnou očekávanou `lock_version`. Služba zamkne objednávku a thread, porovná request hash a v jediné transakci zapíše thread, event, audit i ledger. Shodný replay vrátí původní event, jiný payload se stejným klíčem je konflikt a dva verdikty nad stejnou verzí nemohou oba uspět.
