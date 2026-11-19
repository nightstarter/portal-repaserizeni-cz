# Fáze 6C – doménové jádro výkupu řízení

## Systémová zakázka a číslování

Výkup vzniká výhradně přes `BuybackOrderService` jako `OrderSource::SYSTEM_BUYOUT`, `OrderType::BUYOUT`, s `source_order_id = NULL`. Ve stejné transakci vznikne oddělený `order_customers`, fyzický `BUYOUT_ITEM`, větev `BUYOUT`, první `thread_event` a audit.

Číslo má formát `VYK-RRRR-NNNNN`. Tabulka `buyback_order_sequences` drží roční čítač. Řádek roku se zamyká `FOR UPDATE`, navýší se ve stejné transakci a `orders.order_no` zůstává unikátní databázovým omezením. `orders.creation_idempotency_key` a hash požadavku zajistí bezpečný replay a odmítnou použití stejného klíče pro jiný obsah.

## Ceny a oprávnění

`buyback_price_quotes` je append-only verzovaná historie v minor units:

- `INTERNAL_AK` – interní návrh AUTOKLUBY;
- `POLAND_OFFER` – nabídka Polska, včetně více verzí;
- `FINAL_CUSTOMER` – ručně zadaná finální cena zákazníkovi.

Měna je ISO kód a výpočty nepoužívají float. Doporučených 70 % se počítá celočíselně s půlkovým zaokrouhlením nahoru; je pouze odvozené metadata a nikdy nevytvoří finální cenu.

Samostatné oprávnění `BUYBACK_FINAL_PRICE_APPROVE` je ve výchozím mapování pouze pro aktivní `AK_ADMIN`. Chrání ruční stanovení ceny, potvrzení nabídky i její zařazení do outboxu. `AK_OPERATOR` připravuje workflow, ale tyto tři kroky nesmí provést. Polsko může pouze zadat vlastní nabídku. `BUYBACK_PAYMENT_CONFIRM` je rovněž pouze pro `AK_ADMIN`.

## Stavový automat

Povolená hlavní cesta:

`DRAFT → WAITING_FOR_PL_OFFER → PL_OFFERED → WAITING_FOR_FINAL_PRICE → FINAL_PRICE_APPROVED → CUSTOMER_OFFER_READY → OFFER_SENT → CUSTOMER_ACCEPTED → ITEM_RECEIVED_AK → ITEM_CHECKED_AK`

Poté je volba auditovaná a nevratná běžnou akcí:

- `RETAINED_AK` – terminální;
- `READY_TO_PL → SENT_TO_PL → RECEIVED_PL → INVOICE_TO_PL_REQUIRED → WAITING_FOR_PL_PAYMENT → PAID_BY_PL` – terminální.

Opakovaná polská nabídka ponechá stav `PL_OFFERED`, ale přidá další verzi ceny a událost. Libovolný stavový select je zakázaný. Každý příkaz používá transakci, row lock, `lock_version`, idempotency key, append-only `thread_events` a audit.

## Outbox

`QUEUE_BUYBACK_CUSTOMER_OFFER` vytvoří v téže transakci idempotentní event `BUYBACK_CUSTOMER_OFFER_EMAIL` ve stávajících `outbox_events`. Payload obsahuje pouze systémové číslo zakázky, nezbytný e-mail příjemce, částku/měnu a ID události; neobsahuje jméno, telefon, adresu, technické interní údaje ani storage path.

`BUYBACK_CUSTOMER_EMAIL_ENABLED=false` je bezpečný výchozí stav. Fáze 6C nepřidává SMTP worker pro tento typ a neposílá skutečný e-mail. Pending event se neztrácí. Přijetí nabídky eviduje AUTOKLUBY ručně až po stavu `OFFER_SENT`; není vytvořen veřejný zákaznický odkaz.

## Zásilky

Přeprava výkupu AUTOKLUBY → Polsko používá existující `ShipmentService`, `shipment_items`, QR, rezervaci `active_shipment_id`, směrové kontroly a idempotenci zásilek. `SEND_BUYBACK_TO_PL` a `CONFIRM_BUYBACK_PL_RECEIPT` jsou na HTTP command endpointu odmítnuté. Doménová služba navíc vyžaduje odpovídající aktivní zásilku a stav `PREPARED`/`SENT`, takže osamocený příkaz dopravu neobejde. Stav zásilky a BUYBACK se mění atomicky ve stejné transakci.

## Dokumenty a finance

Faktura AUTOKLUBY → Polsko používá rozšířený `PrivateDocumentService`, stávající privátní disk, kontrolu MIME/přípony/velikosti/checksumu, tokenizovaný autorizovaný download a `documents`/`document_events`. Aktivní neuhrazenou fakturu lze opravit pouze vytvořením nové verze a invalidací původní.

Po nahrání faktury přejde větev do `WAITING_FOR_PL_PAYMENT`. Pouze `BUYBACK_PAYMENT_CONFIRM` ji může označit zaplacenou. Tato akce přidá `BUYBACK_POLAND_PAYMENT` do existujícího append-only `finance_entries` a audit; staré záznamy nepřepisuje.

## Viditelnost a endpointy

- `POST /buybacks` (`buybacks.store`)
- `POST /orders/{order}/buyback/{thread}/actions/{action}` (`orders.buyback.actions`)
- `POST /orders/{order}/buyback/{thread}/invoice` (`orders.buyback.invoice.store`)
- `POST /orders/{order}/buyback/{thread}/invoice/{document}/replace` (`orders.buyback.invoice.replace`)
- stávající `GET /documents/{document}/download`

Polsko získá row-level přístup až při svém úkolu nebo vlastní události. Přímý přístup k cizí větvi vrací 404. Polské HTTP odpovědi a existující read-only detail nenačítají `order_customers`, interní/final customer ceny ani zákaznickou nabídku. Faktura k platbě je přístupná přes existující document policy.

## Testovací a provozní bezpečnost

Testy používají výhradně `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Dokumentové testy vytvářejí unikátní sandbox pod systémovým temp adresářem a uklízejí jej v `tearDown()`.

Produkční DB, `.env`, cron, private storage ani e-mail nebyly změněny. Migrace 000013 nebyla spuštěna v produkci. `DISCUSSION_EMAIL_ENABLED`, `BUYBACK_CUSTOMER_EMAIL_ENABLED`, `LEGACY_SHOP_IMPORT_ENABLED` a `POHODA_BRIDGE_ENABLED` mají zůstat `false`.

## Známá omezení

- Fáze 6C je doménové/API jádro; finální fronta a uživatelské obrazovky výkupu nejsou součástí této fáze.
- Skutečné zákaznické e-maily a jejich retry worker nejsou zapojené; outbox zůstává pending pro pozdější fázi.
- Výchozí oprávnění jsou mapována na role v kódu. Administrátorské UI pro individuální přidělování oprávnění není součástí 6C.
- Doporučených 70 % se Polsku samostatně nezobrazuje, protože MASTER výslovně neurčuje jeho polskou viditelnost.
- Souběh číslování je chráněn DB row lockem a unikátním indexem; SQLite `:memory:` testuje invarianty a rychlé sekvenční kolize, nikoli síťový souběh více MariaDB spojení.
