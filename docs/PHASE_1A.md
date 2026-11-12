# Fáze 1A – datové jádro

## Rozsah

Implementována je pouze první část Fáze 1:

- doménové tabulky a modely `orders`, `order_customers`, `items`, `process_threads`, `thread_events`, `integration_events` a `integration_checkpoints`,
- existující `organizations`, `users` a `audit_log` z Fáze 0 jsou zachovány a doplněny pouze o potřebné vztahy; audit získal volitelnou vazbu na zakázku,
- náhodné veřejné QR tokeny fyzických kusů,
- unikátní identita integračních eventů `(integration, external_event_id)`,
- transakční a idempotentní továrna počátečních větví pro zakázkovou repasi, skladovku Děčín, skladovku Polsko a výkup,
- neměnný počáteční `THREAD_CREATED` event se snapshotem odpovědné organizace,
- read-only dashboard a detail zakázky s paralelními větvemi a fyzickými kusy,
- row-level viditelnost Polska pro aktuálně nebo historicky relevantní zakázky,
- zákaznické osobní údaje se v polském detailu nezobrazují,
- CZ/PL překlady nových obrazovek.

## Výslovně mimo rozsah

- legacy MySQL připojení a mapování PrestaShopu,
- produkční nebo testovací importní příkaz, scheduler a cron,
- připojení k produkčnímu e-shopu nebo Pohodě,
- stavové přechody a mutační workflow akce Fáze 2,
- zásilky, finance, dokumenty, diskuse, reklamace a skutečné výkupní workflow dalších fází.

`LEGACY_SHOP_IMPORT_ENABLED` a `POHODA_BRIDGE_ENABLED` zůstávají ve výchozím i testovacím prostředí vypnuté.

## Testovací data a akceptace

Testovací data vytvářejí Laravel model factories; žádná produkční osobní data ani externí spojení se nepoužívají.

Ověřeno je zejména:

- jedna zakázka může mít více nezávislých větví a fyzických kusů,
- opakované vytvoření počátečních větví nevytváří duplicity,
- každá počáteční větev má neměnný event,
- integrační event nelze pro stejnou integraci a externí ID vložit dvakrát,
- AUTOKLUBY vidí všechny testovací zakázky,
- Polsko vidí pouze aktuálně nebo historicky relevantní zakázky,
- Polsko nevidí zákaznický e-mail, telefon ani adresu,
- produkční integrace zůstávají vypnuté.
