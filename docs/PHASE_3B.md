# Fáze 3B – UI zásilek a QR skener

Fáze 3B zpřístupňuje doménu zásilek z Fáze 3A pracovníkům AUTOKLUBY a Polska. Nepřidává druhou implementaci workflow: všechny mutace HTTP vrstvy volají výhradně `ShipmentService`, takže atomické přechody větví, aktivní rezervace, idempotence a optimistické zamykání zůstávají jediným zdrojem pravdy.

## Rozsah

- seznam zásilek omezený na odesílající nebo přijímající organizaci;
- vytvoření zásilky ve směru vlastní organizace, včetně administrátorského override z Fáze 3A;
- detail s fyzickými kusy, zakázkami, větvemi, trackingem a neměnnou historií;
- hromadné přidávání kusů načtením QR tokenu, celého QR odkazu nebo kamerou přes browser `BarcodeDetector`;
- odebrání kusu před expedicí;
- jednorázový tracking celé GLS/DPD zásilky a atomické odeslání všech větví;
- potvrzení doručení a nahlášení chyby přepravy;
- české a polské texty a responzivní mobilní tabulky.

Kamera je progresivní rozšíření. Pokud prohlížeč `BarcodeDetector` nebo přístup ke kameře neposkytuje, formulář zůstává plně použitelný s hardwarovou QR čtečkou nebo ručním vložením tokenu/odkazu.

## Bezpečnost

- detail i seznam jsou omezené na obě organizace konkrétní zásilky;
- read-only uživatel vidí zásilku, ale nevidí mutační formuláře;
- odesílatel mění seznam pouze ve stavu `PREPARED`, příjemce potvrzuje pouze `SENT`/`TRANSPORT_ERROR`;
- každý formulář posílá unikátní idempotency key a aktuální `lock_version`;
- QR token se na serveru převádí na fyzický item a procesní větev se odvozuje z role itemu; klient neposílá thread ani cílový stav;
- konflikt souběžné změny se vrací jako bezpečná uživatelská chyba bez částečného zápisu.

## Ověření

`tests/Feature/Phase3bShipmentUiTest.php` pokrývá vytvoření a viditelnost pro oba účastníky, přidání pomocí celého QR odkazu, bezpečný stale request a kompletní HTTP tok odeslání a příjmu s posunem procesní větve.

Fáze nemění migraci `2026_11_12_000006_create_phase_3a_shipments` a nespouští produkční migrace, import, scheduler ani carrier API. Zákaznické přepravy, přílohy dopravní chyby, finance, detailní vratky, dokumenty, diskuse a notifikace zůstávají mimo rozsah.
