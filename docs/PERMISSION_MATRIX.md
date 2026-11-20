# Permission matrix – Fáze 8A

Jediný strojově čitelný zdroj rolových schopností je `config/permission_matrix.php`. Hodnota `true` nikdy neobchází row-level scope, aktuální stav ani doménový guard. Serverová policy/guard je autorita; skryté tlačítko je pouze UX.

| Oblast | AK_ADMIN | AK_OPERATOR | PL_OPERATOR | READ_ONLY |
| --- | --- | --- | --- | --- |
| Dashboard / seznam / detail | všechny zakázky | všechny zakázky | pouze přidělené/historicky oprávněné | čtení v scope organizace |
| Zákaznické PII | ano | ano | ne | ne |
| Zákaznické ceny a interní finance | ano | ano dle workflow | ne; jen vlastní dodavatelské hodnoty | ne |
| Dokumenty a přílohy | autorizované | autorizované | jen viditelný thread/order | pouze download v read scope |
| Diskuse / unread | číst a psát | číst a psát | číst a psát v row scope | pouze číst; GET ani POST nezapisuje read stav |
| Zásilky a QR | všechny směry + override dle guardu | AK provozní směry | PL provozní směry, jen vlastní scope | pouze číst |
| CORE_RETURN | plný náhled, auditovaný finance override | AK provozní akce | přidělené PL akce/finance | pouze číst |
| WARRANTY | všechny cykly + override | AK provozní akce | přidělené cykly a PL akce | pouze číst |
| BUYBACK | schválení ceny, nabídky, platby | AK provozní kroky bez admin schválení | vlastní nabídka a přidělené PL kroky | pouze číst bez PII/cen |
| Uživatelé / notifikace | ano | ne | ne | ne |
| Pohoda diagnostika / readiness | ano | ne | ne | ne |
| Admin override | ano, vždy s důvodem a auditem | ne | ne | ne |
| Doménový zápis | ano | AK akce | PL akce v row scope | nikdy |

## Povinné serverové vektory

- Přímá URL i POST nejprve ověří viditelnost parent orderu; cizí objekt vrací `404`.
- Child route ověřuje vazbu `order → thread/item/document`, zásilka účast organizace a QR konkrétní item/thread. Znalost sekvenčního ID ani náhodného tokenu neopravňuje přístup.
- Polský scope vyžaduje odpovědnou organizaci nebo historickou událost jeho organizace. Vyhledávání, queue, detail, QR, soubory i POST používají tentýž scope.
- READ_ONLY je odmítnut ve workflow, shipment, discussion, warranty, buyback, finance/document a admin zápisech. Změna vlastního hesla/jazyka je bezpečnostní/uživatelská preference, nikoli doménový zápis.
- CSRF, validační pravidla, idempotency key a optimistic lock doplňují autorizaci; nenahrazují ji.

## Automatizované pokrytí

`Phase8aPermissionMatrixTest` ověřuje úplnost centrální matice, bezpečné admin odpovědi, přímou cizí URL/POST a nulový zápis READ_ONLY unread/message state. Existující akceptační suite dále pokrývají:

- `PhaseTwoAcceptanceTest`, `CustomRebuildWorkflowTest`, `StockRebuildWorkflowTest`: role, organizace, cizí order/thread a admin override;
- `ShipmentWorkflowTest`, `Phase3bShipmentUiTest`: cizí shipment/item/QR, směr a aktivní rezervace;
- `Phase4AcceptanceTest`, `Phase4bDocumentsAndFinanceTest`: CORE_RETURN, finance, cizí dokument, private download;
- `Phase5aDiscussionTest`, `Phase5bDiscussionUiTest`, `Phase5dSecurityTest`: diskuse, attachment, unread, PII a bezpečné 404;
- `WarrantyWorkflowTest`, `Phase6bWarrantyUiTest`: WARRANTY, cizí item/QR/cyklus a interní důvod;
- `BuybackWorkflowTest`, `Phase6eAcceptanceTest`: BUYBACK, PII, ceny, schvalovací permission a admin endpointy.

Výkonové regrese porovnávají malé a velké datasety a nepovolují růst počtu SELECTů s počtem řádků. Admin readiness stránka má samostatný konstantní query-count test.
