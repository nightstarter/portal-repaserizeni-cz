# Fáze 4B — soukromé dokumenty a UI vratkových financí

Fáze 4B navazuje na jediný `CORE_RETURN` workflow a append-only ledger z Fáze 4A. Nepřidává paralelní finanční stav ani druhý workflow engine.

## Dodané části

- soukromé `SUPPLIER_INVOICE` a `SUPPLIER_CREDIT_NOTE` s verzováním, checksumem a náhodnou storage cestou;
- atomické vytvoření nákupní ceny a explicitní dodavatelské zálohy / záznamu bez zálohy z faktury;
- přesné dobropisy pro plně i kráceně schválenou vratku;
- přechody `CREDIT_ISSUED` a `CREDIT_PAID` ve stávajícím stavovém automatu;
- opravy faktur a dobropisů přes invalidaci dokumentu a nové reverzní/korekční ledger entries;
- autorizované stahování, detail vratky, akční formuláře, doklady, ledger a odvozené KPI;
- row-level polská viditelnost bez zákaznických PII a `CUSTOMER_DEPOSIT_SNAPSHOT`.

## Transakční hranice

Soubor se nejprve validuje a uloží na private disk. V jediné DB transakci se pak zamkne order a thread, ověří `expected_lock_version`, zapíší metadata dokumentu, event, audit, ledger a případný přechod. Selhání storage nevytvoří DB záznamy; selhání DB smaže právě uložený osiřelý soubor. Replay stejného idempotency key a request hashe vrátí původní výsledek, rozdílný payload je konflikt.

## Omezení fáze

Fáze neprovádí zákaznickou refundaci, diskuse, notifikace, přecenění, ostrý import ani scheduler. Produkční migrace nebyly při implementaci spuštěny.

## Ověření

Testy používají pouze SQLite `:memory:` a `Storage::fake`. Pokrývají formáty a MIME, velikost, dvojité přípony, náhodnou cestu, checksum, oprávnění a download hlavičky, storage/DB rollback, faktury s i bez zálohy, opravy, dobropisy, platbu, audit, idempotenci a regrese Fází 2–4A.
