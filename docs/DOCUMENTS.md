# Soukromé dokumenty

## Model

`documents` ukládá order, volitelný thread, stabilní typ, náhodný veřejný token, private disk a náhodnou interní cestu, očištěný původní název, skutečný MIME, příponu, velikost, SHA-256, číslo a datum dokladu, decimal částku, ISO měnu, uploader/organizaci, stav a vazbu na nahrazenou verzi. `document_events` je neměnná historie s aktérem, idempotency key, request hashem a důvodem.

Podporované typy jsou `SUPPLIER_INVOICE` a `SUPPLIER_CREDIT_NOTE`; stav je `ACTIVE` nebo `INVALIDATED`. `active_key` databázově zajišťuje nejvýše jednu aktivní fakturu obchodní větve a jeden aktivní dobropis vypořádání.

## Upload

Výchozí allowlist je PDF (`application/pdf`), JPEG (`image/jpeg`) a PNG (`image/png`); limit je konfigurovatelných 10 MB v `config/documents.php`. Server současně kontroluje jedinou bezpečnou příponu, deklarovaný i skutečný MIME z `fileinfo`, velikost a platnost uploadu. PHP, HTML, SVG, JavaScript, dvojité přípony a nesoulad MIME jsou odmítnuty. Framework před službou odstraní klientskou adresářovou část názvu; fyzická cesta se vždy generuje nezávislým UUID.

Disk `local` míří do `storage/app/private`, mimo `public/`, a nemá veřejné URL. Uživatelský název se nikdy nepoužije jako cesta.

## Stažení

Route používá náhodný `public_token`, vyžaduje přihlášení a policy kontroluje row-level viditelnost order/threadu. Polsko může stáhnout jen dokument relevantní viditelné větve; invalidovaný dokument není běžně dostupný. Chybějící i nepovolený token vrací shodně 404. Odpověď je attachment s očištěným názvem, `X-Content-Type-Options: nosniff` a privátním no-store cache nastavením.

## Verze a opravy

Dokument se nepřepisuje ani fyzicky nemaže. Oprava s povinným důvodem invaliduje aktivní verzi, zachová její soubor a metadata a vytvoří novou aktivní verzi. Finanční dopad se neguje novými `REVERSAL` entries a zapisuje znovu. Dobropis lze opravovat pouze ve stavu `CREDIT_ISSUED`, tedy před potvrzením platby.

## Provozní omezení

DB a `storage/app/private` se zálohují a obnovují jako jeden konzistentní celek; checksum umožňuje kontrolu po obnově. Invalidované dokumenty se automaticky nemažou. Antivirová kontrola zatím není implementovaná. Produkční storage setup, PHP limity a rollback popisuje `PHASE_4_DEPLOYMENT.md`.
