# Integrace

Nejsou aktivní žádné produkční integrace.

## Starý e-shop

- `LEGACY_SHOP_IMPORT_ENABLED=false` je povinný výchozí stav.
- Fáze 1B obsahuje bezpečně vypnuté připojení, read-only diagnostiku a importní službu; podrobnosti jsou v `docs/PHASE_1B.md`.
- Import se nesmí zapnout bez ověřeného mapování a výslovně potvrzeného `LEGACY_SHOP_IMPORT_FROM` nebo `LEGACY_SHOP_MIN_ORDER_ID`.
- Legacy databázový účet musí mít pouze oprávnění `SELECT`; aplikace do PrestaShopu nikdy nezapisuje.
- Názvy tabulek, sloupců, stavů úhrady ani procesních hodnot se nesmí odhadovat. Všechny mapy musí být ručně ověřeny a nakonfigurovány přes `.env`.
- Produkční připojení, živé ověření mapování, dry-run nad živými daty, aktivace importu a scheduler jsou po Fázi 1B stále neprovedené.

## Pohoda

- `POHODA_BRIDGE_ENABLED=false` je povinný výchozí stav.
- Portál nesmí navazovat příchozí spojení do firemní sítě.
- Bridge a podepisované API patří do Fáze 7.
